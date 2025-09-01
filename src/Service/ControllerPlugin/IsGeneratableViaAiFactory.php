<?php

declare(strict_types=1);

namespace AiGenerator\Service\ControllerPlugin;

use AiGenerator\Mvc\Controller\Plugin\IsGeneratableViaAi;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class IsGeneratableViaAiFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');

        return new IsGeneratableViaAi(
            $settings->get('aigenerator_derivative') ?: 'large'
        );
    }
}
