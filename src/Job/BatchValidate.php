<?php declare(strict_types=1);

namespace AiGenerator\Job;

use AiGenerator\Api\Representation\AiRecordRepresentation;
use Omeka\Job\AbstractJob;
use Omeka\Stdlib\ErrorStore;

/**
 * Validate all generations.
 */
class BatchValidate extends AbstractJob
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
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var \AiGenerator\Mvc\Controller\Plugin\ValidateRecordOrCreateOrUpdate
     */
    protected $validateRecordOrCreateOrUpdate;

    /**
     * @var array
     */
    protected $aiRecordIds;

    public function perform()
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('ai-generator/validate/job_' . $this->job->getId());

        $this->acl = $services->get('Omeka\Acl');
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->validateRecordOrCreateOrUpdate = $services->get('ControllerPluginManager')->get('validateRecordOrCreateOrUpdate');

        $query = $this->getArg('query');
        if (!$query) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'A query is required to batch validate ai records. It is not possible to validate all records together. Set a fake argument if needed.', // @translate
            );
            return;
        }

        $this->aiRecordIds = $this->api->search('ai_records', $query, ['returnScalar' => 'id'])->getContent();
        if (!count($this->aiRecordIds)) {
            $this->logger->warn(
                'There is no ai record to validate or you do not have rights ro process them.' // @translate
            );
            return;
        }

        $this->logger->info('Process started'); // @translate

        $index = 0;
        $validated = 0;
        foreach (array_chunk($this->aiRecordIds, 100) as $ids) {
            if ($this->shouldStop()) {
                break;
            }
            foreach ($ids as $id) {
                ++$index;
                $aiRecord = $this->api->read('ai_records', $id)->getContent();
                $result = (bool) $this->validate($aiRecord);
                // Keep list of ids unvalidated?
                if ($result) {
                    ++$validated;
                }
            }
            $this->logger->info(
                '{processed}/{total} resources processed.', // @translate
                ['processed' => $index, 'total' => count($this->aiRecordIds)]
            );
        }

        $this->logger->notice(
            'Process ended. {processed}/{total} ai records processed; {count} validated.', // @translate
            ['processed' => $index, 'total' => count($this->aiRecordIds), 'count' => $validated]
        );
    }

    protected function validate(AiRecordRepresentation $aiRecord)
    {
        // If there is no resource, create it as a whole.
        $relatedResource = $aiRecord->resource();

        // Only people who can edit the resource can validate.
        if (($relatedResource && !$relatedResource->userIsAllowed('update'))
            || (!$relatedResource && !$this->acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            $this->logger->err(
                'AI record #{ai_record_id}: user has no right to process.', // @translate
                ['ai_record_id' => $aiRecord->id()]
            );
            return false;
        }

        $resourceData = $aiRecord->proposalToResourceData();
        if (!$resourceData) {
            $this->logger->err(
                'AI record #{ai_record_id}: not valid.', // @translate
                ['ai_record_id' => $aiRecord->id()]
            );
            return false;
        }

        // Validate and update the resource.
        // The status "reviewed" is set to true, because a validation requires
        // a review.

        $errorStore = new ErrorStore();
        $resource = $this->validateRecordOrCreateOrUpdate->__invoke($aiRecord, $resourceData, $errorStore, true, false, false);

        if ($errorStore->hasErrors()) {
            foreach ($errorStore->getErrors() as $error) {
                $this->logger->err($error);
            }
            return false;
        }

        if (!$resource) {
            $this->logger->err(
                'An internal error occurred.' // @translate
            );
            return false;
        }

        $this->logger->info(
            'The resource #{resource_id} is validated.', // @translate
            ['resource_id' => $resource->id()]
        );

        return true;
    }
}
