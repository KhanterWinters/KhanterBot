<?php
namespace core;

use Discord\Discord;
use Discord\Parts\Channel\Message;

class ModuleManager
{
    private Discord $discord;
    private array $loaded = [];   // nombre => instancia

    public function __construct(Discord $discord)
    {
        $this->discord = $discord;
    }

    public function load(string $moduleName): bool
    {
        if (isset($this->loaded[$moduleName])) return true;

        $class = "Modules\\{$moduleName}";
        if (!class_exists($class)) return false;

        $instance = new $class($this->discord);
        $this->loaded[$moduleName] = $instance;

        // Si el módulo tiene init()
        if (method_exists($instance, 'init')) {
            $instance->init();
        }
        return true;
    }

    public function unload(string $moduleName): bool
    {
        if (!isset($this->loaded[$moduleName])) return false;

        $instance = $this->loaded[$moduleName];
        if (method_exists($instance, 'shutdown')) {
            $instance->shutdown();
        }
        unset($this->loaded[$moduleName]);
        return true;
    }

    public function handleMessage(Message $message): void
    {
        foreach ($this->loaded as $module) {
            if (method_exists($module, 'handle')) {
                $module->handle($message);
            }
        }
    }

    public function listLoaded(): array
    {
        return array_keys($this->loaded);
    }
}
