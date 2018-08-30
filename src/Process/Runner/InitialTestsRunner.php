<?php
/**
 * Copyright © 2017-2018 Maks Rafalko
 *
 * License: https://opensource.org/licenses/BSD-3-Clause New BSD License
 */

declare(strict_types=1);

namespace Infection\Process\Runner;

use Infection\EventDispatcher\EventDispatcherInterface;
use Infection\Events\InitialTestCaseCompleted;
use Infection\Events\InitialTestSuiteFinished;
use Infection\Events\InitialTestSuiteStarted;
use Infection\Process\Builder\ProcessBuilder;
use Symfony\Component\Process\Process;

/**
 * @internal
 */
final class InitialTestsRunner
{
    private const ERROR_TIMEOUT = 10;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * InitialTestsRunner constructor.
     *
     * @param ProcessBuilder $processBuilder
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(ProcessBuilder $processBuilder, EventDispatcherInterface $eventDispatcher)
    {
        $this->processBuilder  = $processBuilder;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function run(string $testFrameworkExtraOptions, bool $skipCoverage, array $phpExtraOptions = []): Process
    {
        /** @var Process $process */
        $process = $this->processBuilder->getProcessForInitialTestRun(
            $testFrameworkExtraOptions,
            $skipCoverage,
            $phpExtraOptions
        );

        //Tracking Process Error Exit
        $expirationData = (object) [
            'time' => null,
        ];

        $this->eventDispatcher->dispatch(new InitialTestSuiteStarted());

        $process->run(function ($type) use ($process, $expirationData): void {
            if ($process::ERR === $type) {
                //If already started, do not start again and let parent one run - prevent infinite loop.
                //->isRunning calls the callback again every time there is any updated output.
                if ($expirationData->time !== null) {
                    return;
                }

                //Give The Error Processing Time To Fully Output
                $expirationData->time = time() + static::ERROR_TIMEOUT;

                while ($process->isRunning() && time() < $expirationData->time) {
                    usleep(100);
                };

                $process->stop();
            }

            $this->eventDispatcher->dispatch(new InitialTestCaseCompleted());
        });

        $this->eventDispatcher->dispatch(new InitialTestSuiteFinished());

        return $process;
    }
}
