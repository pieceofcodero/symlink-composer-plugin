<?php

namespace Pieceofcodero\SymlinkComposerPlugin;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Pieceofcodero\SymlinkComposerPlugin\Service\SymlinkManager;

class SymlinkRecreateCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('symlink-recreate-all')
            ->setDescription('Recreates all symlinks defined in the symlink-paths configuration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->getComposer();
        $io = $this->getIO();
        
        // Create our own SymlinkManager instance
        $symlinkManager = new SymlinkManager($composer, $io);
        $symlinkManager->createSymlinksForAllPackages();

        return 0; // Success
    }
}
