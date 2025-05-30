<?php

namespace Pieceofcodero\SymlinkComposerPlugin;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SymlinkRecreateCommand extends BaseCommand
{
    protected function configure()
    {
        $this
            ->setName('symlink-recreate-all')
            ->setDescription('Recreates all symlinks defined in the symlink-paths configuration.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $composer = $this->getComposer();
        $pluginManager = $composer->getPluginManager();
        
        // Find our plugin instance
        foreach ($pluginManager->getPlugins() as $plugin) {
            if ($plugin instanceof Plugin) {
                // Work directly with the SymlinkManager instead of delegating through the Plugin
                $symlinkManager = $plugin->getSymlinkManager();
                $symlinkManager->createSymlinksForAllPackages();
                return 0; // Success
            }
        }

        $output->writeln('<error>Could not find the Symlink Plugin instance.</error>');
        return 1; // Error
    }
}
