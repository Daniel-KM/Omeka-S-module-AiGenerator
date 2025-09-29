<?php

declare(strict_types=1);

namespace AiGenerator\Job;

use AiGenerator\Api\Representation\AiRecordRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\ErrorStore;

class AiRecordsGenerate extends AbstractJob
{
    /**
     * @var \Omeka\Permissions\Acl
     */
    protected $acl;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \AiGenerator\Mvc\Controller\Plugin\GenerateViaOpenAi
     */
    protected $generateViaOpenAi;

    /**
     * @var array
     */
    protected $aiRecordIds;

    public function perform(): void
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('ai-generator/validate/job_' . $this->job->getId());

        $this->acl = $services->get('Omeka\Acl');
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->entityManager = $services->get('Omeka\EntityManager');
        $plugins = $services->get('ControllerPluginManager');
        $this->generateViaOpenAi = $plugins->get('generateViaOpenAi');

        $query = $this->getArg('query');
        if (!$query) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'A query is required to batch validate ai records. It is not possible to validate all records together. Set a fake argument if needed.', // @translate
            );
            return;
        }

        // Ids may be items or medias.
        $itemIds = $this->api->search('items', $query, ['returnScalar' => 'id'])->getContent();
        $mediaIds = $this->api->search('media', $query, ['returnScalar' => 'id'])->getContent();
        if (!$itemIds && !$mediaIds) {
            $this->logger->warn(
                'There is no resource to generate or you do not have rights ro process them.' // @translate
            );
            return;
        }

        $options = $this->getArg('options', []) ?: [];

        $resourceIds = array_fill_keys($itemIds, 'items') + array_fill_keys($mediaIds, 'media');
        unset($itemIds, $mediaIds);

        $this->logger->notice(
            "Generate ai records for {count} resources with options:\n{json}", // @translate
            ['count' => count($resourceIds), 'json' => $options],
        );

        $index = 0;
        $failed = 0;
        foreach (array_chunk($resourceIds, 100, true) as $ids) {
            if ($this->shouldStop()) {
                break;
            }
            $succeeds = [];
            $faileds = [];
            foreach ($ids as $id => $resourceType) {
                ++$index;
                $resource = $this->api->read($resourceType, $id)->getContent();
                $result = $this->generateViaOpenAi->__invoke($resource, $options);
                // TODO Keep list of ids failed?
                if ($result) {
                    $succeeds[] = $id;
                } else {
                    // The logs are already included.
                    $faileds[] = $id;
                    ++$failed;
                }
            }
            if ($succeeds && $faileds) {
                $this->logger->info(
                    '{processed}/{total} resources processed. Succeed: {resource_ids}. Failed: {resource_ids_2}.', // @translate
                    ['processed' => $index, 'total' => count($resourceIds), 'resource_ids' => implode(', ', $succeeds), 'resource_ids_2' => implode(', ', $faileds)]
                );
            } elseif ($succeeds) {
                $this->logger->info(
                    '{processed}/{total} resources processed. Succeed: {resource_ids}. Failed: none.', // @translate
                    ['processed' => $index, 'total' => count($resourceIds), 'resource_ids' => implode(', ', $succeeds)]
                );
            } else {
                $this->logger->info(
                    '{processed}/{total} resources processed. Succeed: none. Faileds: {resource_ids}.', // @translate
                    ['processed' => $index, 'total' => count($resourceIds), 'resource_ids' => implode(', ', $faileds)]
                );
            }

            // Avoid memory issue.
            $this->entityManager->clear();
        }

        $this->logger->notice(
            'Process ended. {processed}/{total} resources processed; {count} failed.', // @translate
            ['processed' => $index, 'total' => count($resourceIds), 'count' => $failed]
        );
    }
}
