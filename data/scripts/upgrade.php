<?php declare(strict_types=1);

namespace Generate;

use Common\Stdlib\PsrMessage;
use Omeka\Module\Exception\ModuleCannotInstallException;
use Omeka\Stdlib\Message;

/**
 * @var Module $this
 * @var \Laminas\ServiceManager\ServiceLocatorInterface $services
 * @var string $newVersion
 * @var string $oldVersion
 *
 * @var \Doctrine\DBAL\Connection $connection
 * @var \Doctrine\ORM\EntityManager $entityManager
 * @var \Omeka\Api\Manager $api
 * @var \Common\Stdlib\EasyMeta $easyMeta
 * @var \Omeka\Mvc\Controller\Plugin\Messenger $messenger
 */
$plugins = $services->get('ControllerPluginManager');
$api = $plugins->get('api');
// $config = require dirname(dirname(__DIR__)) . '/config/module.config.php';
$settings = $services->get('Omeka\Settings');
$easyMeta = $services->get('Common\EasyMeta');
$connection = $services->get('Omeka\Connection');
$messenger = $plugins->get('messenger');
// $entityManager = $services->get('Omeka\EntityManager');

if (!method_exists($this, 'checkModuleActiveVersion') || !$this->checkModuleActiveVersion('Common', '3.4.66')) {
    $message = new Message(
        'The module %1$s should be upgraded to version %2$s or later.', // @translate
        'Common', '3.4.66'
    );
    throw new ModuleCannotInstallException((string) $message);
}
