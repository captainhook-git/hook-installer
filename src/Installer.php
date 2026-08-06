<?php

/**
 * This file is part of CaptainHook
 *
 * (c) Sebastian Feldmann <sf@sebastian-feldmann.info>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CaptainHook\HookInstaller;

use Composer\IO\IOInterface;
use RuntimeException;

class Installer
{
    /**
     * Path to CaptainHook phar file
     *
     * @var string
     */
    private string $executable;

    /**
     * Path to the CaptainHook configuration file
     *
     * @var string
     */
    private string $configuration;

    /**
     * Path to the .git directory
     *
     * @var string
     */
    private string $gitDirectory;

    /**
     * Captain constructor
     *
     * @param string $executable
     * @param string $configuration
     * @param string $gitDirectory
     */
    public function __construct(string $executable, string $configuration, string $gitDirectory)
    {
        $this->executable    = $executable;
        $this->configuration = $configuration;
        $this->gitDirectory  = $gitDirectory;
    }

    /**
     * Install the hooks by executing the Cap'n
     *
     * @param  \Composer\IO\IOInterface $io
     * @param  bool                     $force
     * @param  bool                     $enabled
     * @return void
     */
    public function install(IOInterface $io, bool $force, bool $enabled): void
    {
        // Respect composer CLI settings
        $ansi        = $io->isDecorated() ? ' --ansi' : ' --no-ansi';
        $executable  = escapeshellarg($this->executable);

        // captainhook config and repository settings
        $configuration  = ' -c ' . escapeshellarg($this->configuration);
        $repository     = ' -g ' . escapeshellarg($this->gitDirectory);
        $forceOrSkip    = $force ? ' -f' : ' -s';
        $onlyEnabled    = $enabled ? ' --only-enabled' : '';

        // sub process settings
        $cmd   = escapeshellarg(PHP_BINARY) . ' '  . $executable . ' install'
                 . $ansi . ' --no-interaction' . $forceOrSkip . $onlyEnabled
                 . $configuration . $repository;
        $pipes = [];
        $spec  = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];

        $process = @proc_open($cmd, $spec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('no-process');
        }

        // Loop on process until it exits
        do {
            $status = proc_get_status($process);
        } while ($status['running']);
        $exitCode = $status['exitcode'];
        proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('installation process failed');
        }
    }
}
