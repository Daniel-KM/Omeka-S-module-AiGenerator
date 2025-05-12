<?php declare(strict_types=1);

namespace Generate\Service\ControllerPlugin;

use Generate\Mvc\Controller\Plugin\SendGenerationEmail;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SendGenerationEmailFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new SendGenerationEmail(
            $services->get('Omeka\Mailer'),
            $services->get('Omeka\Logger')
        );
    }
}
