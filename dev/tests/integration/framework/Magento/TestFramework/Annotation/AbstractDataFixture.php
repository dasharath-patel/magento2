<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\TestFramework\Annotation;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DataFixtureDirectivesParser;
use Magento\TestFramework\Fixture\DataFixtureSetup;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Workaround\Override\Fixture\Resolver;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Exception;
use Throwable;

/**
 * Class consist of dataFixtures base logic
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
abstract class AbstractDataFixture
{
    /**
     * @var array
     */
    protected $_appliedFixtures = [];

    /**
     * @var array
     */
    protected $fixtures = [];

    /**
     * Retrieve fixtures from annotation
     *
     * @param TestCase $test
     * @param string|null $scope
     * @return array
     */
    protected function _getFixtures(TestCase $test, $scope = null)
    {
        $annotationKey = $this->getAnnotation();

        if (!empty($this->fixtures[$annotationKey][$this->getTestKey($test)])) {
            return $this->fixtures[$annotationKey][$this->getTestKey($test)];
        }

        $resolver = Resolver::getInstance();
        $resolver->setCurrentFixtureType($annotationKey);
        $annotations = TestCaseAnnotation::getInstance()->getAnnotations($test);
        $annotations = $scope === null ? $this->getAnnotations($test) : $annotations[$scope];
        $existingFixtures = [];
        $objectManager = Bootstrap::getObjectManager();
        $fixtureDirectivesParser = $objectManager->get(DataFixtureDirectivesParser::class);
        $fixtureDataProviderAnnotation = $objectManager->get(DataFixtureDataProvider::class);
        $fixtureDataProvider = $fixtureDataProviderAnnotation->getDataProvider($test);
        foreach ($annotations[$annotationKey] ?? [] as $fixture) {
            $metadata = $fixtureDirectivesParser->parse($fixture);
            if ($metadata['name'] && empty($metadata['data']) && isset($fixtureDataProvider[$metadata['name']])) {
                $metadata['data'] = $fixtureDataProvider[$metadata['name']];
            }
            $existingFixtures[] = $metadata;
        }

        /* Need to be applied even test does not have added fixtures because fixture can be added via config */
        $this->fixtures[$annotationKey][$this->getTestKey($test)] = $resolver->applyDataFixtures(
            $test,
            $existingFixtures,
            $annotationKey
        );

        return $this->fixtures[$annotationKey][$this->getTestKey($test)] ?? [];
    }

    /**
     * Get method annotations.
     *
     * Overwrites class-defined annotations.
     *
     * @param TestCase $test
     * @return array
     */
    protected function getAnnotations(TestCase $test): array
    {
        $annotations = TestCaseAnnotation::getInstance()->getAnnotations($test);

        return array_replace((array)$annotations['class'], (array)$annotations['method']);
    }

    /**
     * Execute fixture scripts if any
     *
     * @param array $fixtures
     * @param TestCase $test
     * @return void
     */
    protected function _applyFixtures(array $fixtures, TestCase $test)
    {
        $objectManager = Bootstrap::getObjectManager();
        $testsIsolation = $objectManager->get(TestsIsolation::class);
        $dbIsolationState = $this->getDbIsolationState($test);
        $testsIsolation->createDbSnapshot($test, $dbIsolationState);
        /* Execute fixture scripts */
        foreach ($fixtures as $fixture) {
            if (is_callable([get_class($test), $fixture['factory']])) {
                $fixture['factory'] = get_class($test) . '::' . $fixture['factory'];
            }
            $fixture['result'] = $this->applyDataFixture($fixture);
            $this->_appliedFixtures[] = $fixture;
        }
        $resolver = Resolver::getInstance();
        $resolver->setCurrentFixtureType(null);
    }

    /**
     * Revert changes done by fixtures
     *
     * @param TestCase|null $test
     * @return void
     */
    protected function _revertFixtures(?TestCase $test = null)
    {
        $objectManager = Bootstrap::getObjectManager();
        $resolver = Resolver::getInstance();
        $resolver->setCurrentFixtureType($this->getAnnotation());
        $appliedFixtures = array_reverse($this->_appliedFixtures);
        foreach ($appliedFixtures as $fixture) {
            $this->revertDataFixture($fixture);
        }
        $this->_appliedFixtures = [];
        $resolver->setCurrentFixtureType(null);

        if (null !== $test) {
            /** @var TestsIsolation $testsIsolation */
            $testsIsolation = $objectManager->get(TestsIsolation::class);
            $dbIsolationState = $this->getDbIsolationState($test);
            $testsIsolation->checkTestIsolation($test, $dbIsolationState);
        }
    }

    /**
     * Return is explicit set isolation state
     *
     * @param TestCase $test
     * @return array|null
     */
    protected function getDbIsolationState(TestCase $test)
    {
        $annotations = $this->getAnnotations($test);
        return $annotations[DbIsolation::MAGENTO_DB_ISOLATION] ?? null;
    }

    /**
     * Get uniq test cache key
     *
     * @param TestCase $test
     * @return string
     */
    private function getTestKey(TestCase $test): string
    {
        return sprintf('%s::%s', get_class($test), $test->getName());
    }

    /**
     * Get annotation name
     *
     * @return string
     */
    abstract protected function getAnnotation(): string;

    /**
     * Applies data fixture and returns the result.
     *
     * @param array $fixture
     * @return array|null
     */
    private function applyDataFixture(array $fixture): ?array
    {
        $objectManager = Bootstrap::getObjectManager();
        $dataFixtureSetup = $objectManager->get(DataFixtureSetup::class);
        try {
            $result = $dataFixtureSetup->apply($fixture['factory'], $fixture['data'] ?? []);
        } catch (Throwable $exception) {
            throw new Exception(
                sprintf(
                    "Unable to apply fixture%s: %s.\n%s\n%s",
                    $fixture['name'] ? '"' . $fixture['name'] . '"' : '',
                    $fixture['factory'],
                    $exception->getMessage(),
                    $exception->getTraceAsString()
                ),
                0,
                $exception
            );
        }

        if ($result !== null && !empty($fixture['name'])) {
            DataFixtureStorageManager::getStorage()->persist(
                $fixture['name'],
                $objectManager->create(DataObject::class, ['data' => $result])
            );
        }

        return $result;
    }

    /**
     * Revert data fixture.
     *
     * @param array $fixture
     */
    private function revertDataFixture(array $fixture): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $dataFixtureSetup = $objectManager->get(DataFixtureSetup::class);
        $registry = $objectManager->get(Registry::class);
        $isSecureArea = $registry->registry('isSecureArea');
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            $dataFixtureSetup->revert($fixture['factory'], $fixture['result'] ?? []);
        } catch (NoSuchEntityException $exception) {
            //ignore
        } catch (Throwable $exception) {
            throw new Exception(
                sprintf(
                    "Unable to revert fixture%s: %s.\n%s\n%s",
                    $fixture['name'] ? '"' . $fixture['name'] . '"' : '',
                    $fixture['factory'],
                    $exception->getMessage(),
                    $exception->getTraceAsString()
                ),
                0,
                $exception
            );
        } finally {
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', $isSecureArea);
        }
    }
}
