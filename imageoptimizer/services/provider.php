<?php

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use My\Plugin\Content\ImageOptimizer\Extension\ImageOptimizer;

return new class implements ServiceProviderInterface {
    public function register(Container $container) {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('content', 'imageoptimizer');
                $app = Factory::getApplication();

                $plugin = new ImageOptimizer($subject, $config);
                $plugin->setApplication($app);
    
                return $plugin;


            }
        );
    }
};
