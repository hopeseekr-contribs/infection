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
    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var int
     */
    private $errorTimeout;

    /**
     * InitialTestsRunner constructor.
     *
     * @param ProcessBuilder $processBuilder
     * @param EventDispatcherInterface $eventDispatcher
     */
    public function __construct(ProcessBuilder $processBuilder, EventDispatcherInterface $eventDispatcher, int $errorTimeout = 10)
    {
        $this->processBuilder = $processBuilder;
        $this->eventDispatcher = $eventDispatcher;
        $this->errorTimeout = $errorTimeout;
    }

    public function run(string $testFrameworkExtraOptions, bool $skipCoverage, array $phpExtraOptions = []): Process
    {
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
                $expirationData->time = time() + $this->errorTimeout;

                do {
                    usleep(1000);
                } while ($process->isRunning() && time() < $expirationData->time);

                $process->stop();
            }

            $this->eventDispatcher->dispatch(new InitialTestCaseCompleted());
        });

        $this->eventDispatcher->dispatch(new InitialTestSuiteFinished());

        return $process;
    }
}
