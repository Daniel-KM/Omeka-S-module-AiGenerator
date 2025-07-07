<?php declare(strict_types=1);

namespace Generate\Job;

use Generate\Api\Representation\GeneratedResourceRepresentation;
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
     * @var \Generate\Mvc\Controller\Plugin\ValidateRecordOrCreateOrUpdate
     */
    protected $validateRecordOrCreateOrUpdate;

    /**
     * @var array
     */
    protected $generatedResourceIds;

    public function perform()
    {
        $services = $this->getServiceLocator();

        // The reference id is the job id for now.
        $referenceIdProcessor = new \Laminas\Log\Processor\ReferenceId();
        $referenceIdProcessor->setReferenceId('generate/validate/job_' . $this->job->getId());

        $this->acl = $services->get('Omeka\Acl');
        $this->api = $services->get('Omeka\ApiManager');
        $this->logger = $services->get('Omeka\Logger');
        $this->validateRecordOrCreateOrUpdate = $services->get('ControllerPluginManager')->get('validateRecordOrCreateOrUpdate');

        $query = $this->getArg('query');
        if (!$query) {
            $this->job->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            $this->logger->err(
                'A query is required to batch validate generations. It is not possible to validate all generations together. Set a fake argument if needed.', // @translate
            );
            return;
        }

        $this->generatedResourceIds = $this->api->search('generated_resources', $query, ['returnScalar' => 'id'])->getContent();
        if (!count($this->generatedResourceIds)) {
            $this->logger->warn(
                'There is no generated resource to validate or you do not have rights ro process them.' // @translate
            );
            return;
        }

        $this->logger->info('Process started'); // @translate

        $index = 0;
        $validated = 0;
        foreach (array_chunk($this->generatedResourceIds, 100) as $ids) {
            if ($this->shouldStop()) {
                break;
            }
            foreach ($ids as $id) {
                ++$index;
                $generatedResource = $this->api->read('generated_resources', $id)->getContent();
                $result = (bool) $this->validate($generatedResource);
                // Keep list of ids unvalidated?
                if ($result) {
                    ++$validated;
                }
            }
            $this->logger->info(
                '{processed}/{total} resources processed.', // @translate
                ['processed' => $index, 'total' => count($this->generatedResourceIds)]
            );
        }

        $this->logger->notice(
            'Process ended. {processed}/{total} resources processed; {count} validated.', // @translate
            ['processed' => $index, 'total' => count($this->generatedResourceIds), 'count' => $validated]
        );
    }

    protected function validate(GeneratedResourceRepresentation $generatedResource)
    {
        // If there is no resource, create it as a whole.
        $generatedResourceResource = $generatedResource->resource();

        // Only people who can edit the resource can validate.
        if (($generatedResourceResource && !$generatedResourceResource->userIsAllowed('update'))
            || (!$generatedResourceResource && !$this->acl->userIsAllowed('Omeka\Api\Adapter\ItemAdapter', 'create'))
        ) {
            $this->logger->err(
                'Generated resource #{generated_resource_id}: user has no right to process.', // @translate
                ['generated_resource_id' => $generatedResource->id()]
            );
            return false;
        }

        $resourceData = $generatedResource->proposalToResourceData();
        if (!$resourceData) {
            $this->logger->err(
                'Generated resource #{generated_resource_id}: not valid.', // @translate
                ['generated_resource_id' => $generatedResource->id()]
            );
            return false;
        }

        // Validate and update the resource.
        // The status "reviewed" is set to true, because a validation requires
        // a review.

        $errorStore = new ErrorStore();
        $resource = $this->validateRecordOrCreateOrUpdate->__invoke($generatedResource, $resourceData, $errorStore, true, false, false);

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
