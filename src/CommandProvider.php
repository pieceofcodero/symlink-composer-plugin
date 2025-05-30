<?php

namespace Pieceofcodero\SymlinkComposerPlugin;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability
{
    public function getCommands()
    {
        return [
            new SymlinkRecreateCommand(),
        ];
    }
}
