<?php

declare(strict_types=1);

namespace AiGenerator\Api\Representation;

use DateTime;
use Omeka\Api\Exception;
use Omeka\Api\Representation\AbstractEntityRepresentation;
use Omeka\Api\Representation\ResourceTemplateRepresentation;
use Omeka\Api\Representation\ValueRepresentation;

class AiRecordRepresentation extends AbstractEntityRepresentation
{
    /**
     * @var \AiGenerator\Entity\AiRecord
     */
    protected $resource;

    /**
     * @var array
     */
    protected $values;

    /**
     * Get the resource name of the corresponding entity API adapter.
     */
    public function resourceName(): string
    {
        return 'ai_records';
    }

    public function getControllerName()
    {
        return 'ai-record';
    }

    public function getJsonLdType()
    {
        return 'o:AiRecord';
    }

    public function getJsonLd()
    {
        $relatedResource = $this->resource();
        $owner = $this->owner();
        $modified = $this->modified();

        return [
            'o:id' => $this->id(),
            'o:resource' => $relatedResource ? $relatedResource->getReference()->jsonSerialize() : null,
            'o:owner' => $owner ? $owner->getReference()->jsonSerialize() : null,
            'o:model' => $this->model(),
            'o:response_id' => $this->responseId(),
            'o:tokens_input' => $this->tokensInput(),
            'o:tokens_output' => $this->tokensOutput(),
            'o:reviewed' => $this->isReviewed(),
            'o:proposal' => $this->proposal(),
            'o:created' => [
                '@value' => $this->getDateTime($this->created())->jsonSerialize(),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ],
            'o:modified' => $modified
                ? [
                    '@value' => $this->getDateTime($modified)->jsonSerialize(),
                    '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
                ]
                : null,
        ];
    }

    /**
     * Get the related resource.
     */
    public function resource(): ?\Omeka\Api\Representation\AbstractResourceEntityRepresentation
    {
        $relatedResource = $this->resource->getResource();
        return $relatedResource
        ? $this->getAdapter('resources')->getRepresentation($relatedResource)
            : null;
    }

    public function owner(): ?\Omeka\Api\Representation\UserRepresentation
    {
        $owner = $this->resource->getOwner();
        return $owner
            ? $this->getAdapter('users')->getRepresentation($owner)
            : null;
    }

    public function model(): string
    {
        return $this->resource->getModel();
    }

    public function responseId(): string
    {
        // The name of the method for the resource is getResponseid().
        return $this->resource->getResponseid();
    }

    public function tokensInput(): int
    {
        return $this->resource->getTokensInput();
    }

    public function tokensOutput(): int
    {
        return $this->resource->getTokensOutput();
    }

    public function isReviewed(): bool
    {
        return $this->resource->getReviewed();
    }

    public function proposal(): array
    {
        return $this->resource->getProposal();
    }

    /**
     * The resource template is the resource one once submitted or when
     * correcting, else the one proposed by the user.
     * For now, this is always the template of the original resource, but the
     * process is kept like Contribute for future evolution.
     */
    public function resourceTemplate(): ?ResourceTemplateRepresentation
    {
        $relatedResource = $this->resource();
        if ($relatedResource) {
            $resourceTemplate = $relatedResource->resourceTemplate();
        }
        if (empty($resourceTemplate)) {
            $proposal = $this->resource->getProposal();
            $resourceTemplateId = $proposal['template'] ?? null;
            if ($resourceTemplateId) {
                $templateAdapter = $this->getAdapter('resource_templates');
                try {
                    $resourceTemplate = $templateAdapter->findEntity(['id' => $resourceTemplateId]);
                    $resourceTemplate = $templateAdapter->getRepresentation($resourceTemplate);
                } catch (Exception\NotFoundException $e) {
                    $resourceTemplate = null;
                }
            }
        }
        return $resourceTemplate;
    }

    public function created(): DateTime
    {
        return $this->resource->getCreated();
    }

    public function modified(): ?DateTime
    {
        return $this->resource->getModified();
    }

    /**
     * Get all proposition for a term.
     */
    public function proposedValues(string $term): array
    {
        $data = $this->proposal();
        return empty($data[$term])
            ? []
            : $data[$term];
    }

    /**
     * Get a specific proposition for a term.
     *
     * @return array|null Empty string value is used when the value is removed.
     */
    public function proposedValue(string $term, string $original): ?array
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if (isset($value['original']['@value'])
                && $value['original']['@value'] === $original
            ) {
                return $value['proposed'];
            }
        }
        return null;
    }

    /**
     * Get a specific proposed generated resource uri for a term.
     *
     * @return array|null Empty string uri is used when the value is removed.
     */
    public function proposedUriValue(string $term, string $originalUri, string $originalLabel): ?array
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if (isset($value['original']['@uri'])
                && $value['original']['@uri'] === $originalUri
                && $value['original']['@label'] === $originalLabel
            ) {
                return $value['proposed'];
            }
        }
        return null;
    }

    /**
     * Check if a value is the same than the resource one.
     *
     * @return bool|null Null means no value, false if edited, true if
     * approved.
     */
    public function isApprovedValue(string $term, string $original): ?bool
    {
        $proposed = $this->proposedValues($term);
        if (empty($proposed)) {
            return null;
        }
        foreach ($proposed as $value) {
            if (($value['original']['@value'] ?? null) === $original) {
                return $value['proposed']['@value'] === $value['original']['@value'];
            }
        }
        return null;
    }

    /**
     * Check if a value exists in original resource.
     */
    public function resourceValue(string $term, string $string): ?ValueRepresentation
    {
        if ($string === '') {
            return null;
        }
        $relatedResource = $this->resource();
        if (!$relatedResource) {
            return null;
        }
        $values = $relatedResource->value($term, ['all' => true]);
        foreach ($values as $value) {
            if ((string) $value->value() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a resource value exists in original resource.
     */
    public function resourceValueResource(string $term, $intOrString): ?\Omeka\Api\Representation\ValueRepresentation
    {
        $int = (int) $intOrString;
        if (!$int) {
            return null;
        }
        $relatedResource = $this->resource();
        if (!$relatedResource) {
            return null;
        }
        $values = $relatedResource->value($term, ['all' => true]);
        $valueResource = null;
        foreach ($values as $value) {
            $valueResource = $value->valueResource();
            if ($valueResource && $valueResource->id() === $int) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check if a uri exists in original resource.
     */
    public function resourceValueUri(string $term, string $string): ?\Omeka\Api\Representation\ValueRepresentation
    {
        if ($string === '') {
            return null;
        }
        $relatedResource = $this->resource();
        if (!$relatedResource) {
            return null;
        }
        // To get only uris and value suggest/custom vocab values require to get all values.
        $values = $relatedResource->value($term, ['all' => true]);
        foreach ($values as $value) {
            if ($value->uri() === $string) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Check proposed generated resource against current resource and normalize it.
     *
     * The proposal does not manage the type of the values.
     * The sub-generated medias are checked too via a recursive call.
     *
     * @todo Factorize with \Contribute\Site\ContributionController::prepareProposal()
     * @todo Factorize with \Contribute\View\Helper\ContributionFields
     * @todo Factorize with \Contribute\Api\Representation\ContributionRepresentation::proposalToResourceData()
     * @todo Factorize with \AiGenerator\Controller\Admin\IndexController::prepareProposal()
     * @todo Factorize with \AiGenerator\View\Helper\AiRecordFields
     * @todo Factorize with \AiGenerator\Api\Representation\AiRecordRepresentation::proposalToResourceData()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     */
    public function proposalNormalizeForValidation(): array
    {
        $generative = $this->generativeData();
        $proposal = $this->proposal();

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');
        $propertyIds = $easyMeta->propertyIds();

        // Use the resource template of the resource or the default one.
        $resourceTemplate = $generative->template();

        // A template is required, but its check should be done somewhere else:
        // here, it's more about standardization of the proposal.
        // if (!$resourceTemplate) {
        //     return [];
        // }

        $proposal['template'] = $resourceTemplate;

        foreach ($proposal as $term => $propositions) {
            // Skip special keys.
            if (in_array($term, ['template', 'media', 'file'])) {
                continue;
            }

            $isEditable = $generative->isTermEditable((string) $term);
            $isFillable = $generative->isTermFillable((string) $term);
            if (!$isEditable && !$isFillable) {
                // Skipped in the case options changed between generated resources and moderation.
                // continue;
            }

            // In the case that the property was removed.
            if (!isset($propertyIds[$term])) {
                unset($proposal[$term]);
                continue;
            }

            $mainType = null;
            $propertyId = $propertyIds[$term];
            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }

            $mainTypeTemplate = $easyMeta->dataTypeMain($typeTemplate);
            $isCustomVocab = substr((string) $typeTemplate, 0, 12) === 'customvocab:';
            $isCustomVocabUri = $isCustomVocab && $mainTypeTemplate === 'uri';
            $uriLabels = $isCustomVocabUri ? $this->customVocabUriLabels($typeTemplate) : [];

            foreach ($propositions as $key => $proposition) {
                // TODO Remove management of proposition without resource template (but the template may have been modified).
                if ($typeTemplate) {
                    $mainType = $mainTypeTemplate;
                } elseif (empty($proposition['original'])) {
                    // Unlike Contribution, "unknown" is like literal to allow
                    // property not listed in template.
                    // It allows to display the value for a property that is not
                    // in the template.
                    // Nevertheless, its process status is "keep", so it cannot
                    // fill the resource.
                    // TODO Check or explain why the case "unknown" is different in Generate and Contribute, here and below. Is patch managed well?
                    // If "unknown", it means that $generativeData->isTermDataType(),
                    // $generativeData->editable() and $generativeData->fillable()
                    // are false, so cannot be used and this is an issue.
                    // $mainType = 'unknown';
                    $mainType = 'literal';
                } elseif (array_key_exists('@uri', $proposition['original'])) {
                    $mainType = 'uri';
                } elseif (array_key_exists('@resource', $proposition['original'])) {
                    $mainType = 'resource';
                } elseif (array_key_exists('@value', $proposition['original'])) {
                    $mainType = 'literal';
                } else {
                    $mainType = 'unknown';
                }

                $isTermDataType = $generative->isTermDataType($term, $typeTemplate ?? $mainType);

                switch ($mainType) {
                    case 'literal':
                        $original = (string) ($proposition['original']['@value'] ?? '');
                        $proposed = (string) ($proposition['proposed']['@value'] ?? '');

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($original);
                        $hasProposition = (bool) strlen($proposed);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValue($term, $proposed)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDataType
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValue($term, $proposed)) {
                            $prop['value'] = $this->resourceValue($term, $original);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValue($term, $original)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValue($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    case 'resource':
                        $original = isset($proposition['original']['@resource']) ? (int) $proposition['original']['@resource'] : 0;
                        $proposed = isset($proposition['proposed']['@resource']) ? (int) $proposition['proposed']['@resource'] : 0;

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) $original;
                        $hasProposition = (bool) $proposed;
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!$proposed) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!$original
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValueResource($term, $proposed)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDataType
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValueResource($term, $proposed)) {
                            $prop['value'] = $this->resourceValueResource($term, $original);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValueResource($term, $original)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueResource($term, $proposed);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    case 'uri':
                        if ($isCustomVocabUri) {
                            $proposedValue['@label'] = $uriLabels[$proposedValue['@uri'] ?? ''] ?? $proposedValue['@label'] ?? '';
                        }

                        $originalUri = $proposition['original']['@uri'] ?? '';
                        $originalLabel = $proposition['original']['@label'] ?? '';
                        $original = $originalUri . $originalLabel;

                        $proposedUri = $proposition['proposed']['@uri'] ?? '';
                        $proposedLabel = $proposition['proposed']['@label'] ?? '';
                        $proposed = $proposedUri . $proposedLabel;

                        // Nothing to do if there is no proposition and no original.
                        $hasOriginal = (bool) strlen($originalUri);
                        $hasProposition = (bool) strlen($proposedUri);
                        if (!$hasOriginal && !$hasProposition) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        // TODO Keep the key order of the value in the list of values of each term to simplify validation.

                        $prop = &$proposal[$term][$key];
                        if ($original === $proposed) {
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = $prop['value'];
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif (!strlen($proposed)) {
                            // If no proposition, the user wants to remove a value, so check if it still exists.
                            // Either the value is validated, either it is not (to be removed, edited or appended).
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = null;
                            $prop['validated'] = !$prop['value'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'remove'
                                // A value to remove is not a fillable value.
                                : 'keep';
                        } elseif (!strlen($original)
                            // Even if there is no original, check if a new
                            // value has been appended.
                            && !$this->resourceValueUri($term, $proposedUri)
                        ) {
                            // The original value may have been removed or appended:
                            // this is not really determinable.
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isFillable && $isTermDataType
                                ? 'append'
                                // A value to append is not an editable value.
                                : 'keep';
                        } elseif ($proposedValue = $this->resourceValueUri($term, $proposedUri)) {
                            $prop['value'] = $this->resourceValueUri($term, $originalUri);
                            $prop['value_updated'] = $proposedValue;
                            $prop['validated'] = true;
                            $prop['process'] = 'keep';
                        } elseif ($originalValue = $this->resourceValueUri($term, $originalUri)) {
                            $prop['value'] = $originalValue;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = $isEditable && $isTermDataType
                                ? 'update'
                                // A value to update is not a fillable value.
                                : 'keep';
                        } else {
                            $prop['value'] = null;
                            $prop['value_updated'] = $this->resourceValueUri($term, $proposedUri);
                            $prop['validated'] = (bool) $prop['value_updated'];
                            $prop['process'] = 'keep';
                        }
                        unset($prop);
                        break;

                    default:
                        $original = $proposition['original']['@value'] ?? '';

                        // TODO Unlike contribution, a generative metadata for another property can be allowed when the template is open? No, too much complex and useless.
                        // Anyway, "unknown" is processed like the case "literal" above.
                        // TODO Copy and simplify literal here?

                        // Nothing to do if there is no original.
                        $hasOriginal = (bool) strlen($original);
                        if (!$hasOriginal) {
                            unset($proposal[$term][$key]);
                            continue 2;
                        }

                        $prop = &$proposal[$term][$key];
                        $prop['value'] = null;
                        $prop['value_updated'] = null;
                        $prop['validated'] = false;
                        $prop['process'] = 'keep';
                        unset($prop);
                        break;
                }
            }
        }

        return $proposal;
    }

    /**
     * Check values of the exiting resource with the proposal and get api data.
     *
     * @todo Factorize with \AiGenerator\Controller\Admin\IndexController::prepareProposal()
     * @todo Factorize with \AiGenerator\View\Helper\AiRecordFields
     * @todo Factorize with \AiGenerator\Api\Representation\AiRecordRepresentation::proposalNormalizeForValidation()
     *
     * @todo Simplify when the status "is patch" or "new resource" (at least remove all original data).
     *
     * @todo Keep existing media during update and check for item sets, sites, class, etc.
     *
     * @param string|null $proposedTerm Validate only a specific term.
     * @param int|null $proposedKey Validate only a specific key for the term.
     * @param bool $isSubTemplate Internal param for recursive call.
     * @param int $indexProposalMedia Internal param for recursive call.
     * @return array Data to be used for api. Files for media are in key "file".
     */
    public function proposalToResourceData(
        ?string $proposedTerm = null,
        ?int $proposedKey = null
    ): ?array {
        // The generated resource requires a resource template in allowed templates.
        $generative = $this->generativeData();
        if (!$generative->isGenerative()) {
            return null;
        }

        $relatedResource = $this->resource();
        $existingValues = $relatedResource ? $relatedResource->values() : [];

        $resourceTemplate = $generative->template();
        $proposal = $this->proposalNormalizeForValidation();
        $hasProposedTermAndKey = $proposedTerm && $proposedKey !== null;

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');
        $propertyIds = $easyMeta->propertyIds();

        // TODO How to update only one property to avoid to update unmodified terms? Not possible with core resource hydration. Simple optimization anyway.

        $data = [
            'o:resource_template' => null,
            'o:resource_class' => null,
            'o:media' => [],
            'file' => [],
        ];
        if ($resourceTemplate) {
            $resourceClass = $resourceTemplate->resourceClass();
            $data['o:resource_template'] = ['o:id' => $resourceTemplate->id()];
            $data['o:resource_class'] = $resourceClass ? ['o:id' => $resourceClass->id()] : null;
        }

        // Clean data for the special keys.
        unset($proposal['template'], $proposal['media'], $proposal['file']);

        // First loop to keep, update or remove existing values.
        foreach ($existingValues as $term => $propertyData) {
            // Keep all existing values.
            $data[$term] = array_map(fn ($v) => $v->jsonSerialize(), $propertyData['values']);
            if ($hasProposedTermAndKey && $proposedTerm !== $term) {
                continue;
            }
            if (!$generative->isTermGenerative($term)) {
                continue;
            }
            /** @var \Omeka\Api\Representation\ValueRepresentation $existingValue */
            foreach ($propertyData['values'] as $existingValue) {
                if (!isset($proposal[$term])) {
                    continue;
                }
                if (!$generative->isTermDataType($term, $existingValue->type())) {
                    continue;
                }

                // Values have no id and the order key is not saved, so the
                // check should be redone.
                $existingVal = $existingValue->value();
                $existingUri = $existingValue->uri();
                $existingResource = $existingValue->valueResource();
                $existingResourceId = $existingResource ? $existingResource->id() : null;
                foreach ($proposal[$term] as $key => $proposition) {
                    if ($hasProposedTermAndKey && $proposedKey != $key) {
                        continue;
                    }
                    if ($proposition['validated']) {
                        continue;
                    }
                    if (!in_array($proposition['process'], ['remove', 'update'])) {
                        continue;
                    }

                    $hasOriginal = !empty($proposition['original']);
                    $isUri = $hasOriginal && array_key_exists('@uri', $proposition['original']);
                    $isResource = $hasOriginal && array_key_exists('@resource', $proposition['original']);
                    $isValue = $hasOriginal && array_key_exists('@value', $proposition['original']);

                    if ($isUri) {
                        if ($proposition['original']['@uri'] === $existingUri) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@id'] = $proposition['proposed']['@uri'];
                                    $data[$term][$key]['o:label'] = $proposition['proposed']['@label'];
                                    break;
                            }
                            break;
                        }
                    } elseif ($isResource) {
                        if ($proposition['original']['@resource'] === $existingResourceId) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['value_resource_id'] = $proposition['proposed']['@resource'];
                                    break;
                            }
                            break;
                        }
                    } elseif ($isValue) {
                        if ($proposition['original']['@value'] === $existingVal) {
                            switch ($proposition['process']) {
                                case 'remove':
                                    unset($data[$term][$key]);
                                    break;
                                case 'update':
                                    $data[$term][$key]['@value'] = $proposition['proposed']['@value'];
                                    break;
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Second loop to convert last remaining propositions into array.
        // Only process "append" should remain.
        foreach ($proposal as $term => $propositions) {
            if ($hasProposedTermAndKey && $proposedTerm !== $term) {
                continue;
            }
            if (!$generative->isTermGenerative($term)) {
                continue;
            }
            $propertyId = $propertyIds[$term] ?? null;
            if (!$propertyId) {
                continue;
            }

            $mainType = null;
            $typeTemplate = null;
            $isPublic = true;
            if ($resourceTemplate) {
                /** @var \Omeka\Api\Representation\ResourceTemplatePropertyRepresentation $resourceTemplateProperty */
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                    $isPublic = !$resourceTemplateProperty->isPrivate();
                }
            }

            $mainTypeTemplate = $easyMeta->dataTypeMain($typeTemplate);
            $isCustomVocab = substr((string) $typeTemplate, 0, 12) === 'customvocab:';
            $isCustomVocabUri = $isCustomVocab && $mainTypeTemplate === 'uri';
            $uriLabels = $isCustomVocabUri ? $this->customVocabUriLabels($typeTemplate) : [];

            foreach ($propositions as $key => $proposition) {
                if ($hasProposedTermAndKey && $proposedKey != $key) {
                    continue;
                }
                if ($proposition['validated']) {
                    continue;
                }
                if ($proposition['process'] !== 'append') {
                    continue;
                }

                if ($typeTemplate) {
                    $mainType = $mainTypeTemplate;
                } elseif (empty($proposition['original'])) {
                    // See above why default type is literal.
                    // $mainType = 'unknown';
                    $mainType = 'literal';
                } elseif (array_key_exists('@uri', $proposition['original'])) {
                    $mainType = 'uri';
                } elseif (array_key_exists('@resource', $proposition['original'])) {
                    $mainType = 'resource';
                } elseif (array_key_exists('@value', $proposition['original'])) {
                    $mainType = 'literal';
                } else {
                    $mainType = 'unknown';
                }

                switch ($mainType) {
                    // Like proposalNormalizeForValidation() and unlike Contribute,
                    // the type "unknown" is like literal.
                    case 'unknown':
                    case 'literal':
                        $data[$term][] = [
                            'type' => $typeTemplate ?? $mainType,
                            'property_id' => $propertyId,
                            '@value' => $proposition['proposed']['@value'],
                            'is_public' => $isPublic,
                            '@language' => $proposition['proposed']['@language'] ?? null,
                        ];
                        break;
                    case 'resource':
                        $data[$term][] = [
                            'type' => $typeTemplate ?? $mainType,
                            'property_id' => $propertyId,
                            'o:label' => null,
                            'value_resource_id' => $proposition['proposed']['@resource'],
                            '@id' => null,
                            'is_public' => $isPublic,
                            '@language' => null,
                        ];
                        break;
                    case 'uri':
                        if ($isCustomVocabUri) {
                            $proposition['proposed']['@label'] = $uriLabels[$proposition['proposed']['@uri'] ?? ''] ?? $proposition['proposed']['@label'] ?? '';
                        }
                        $data[$term][] = [
                            'type' => $typeTemplate ?? $mainType,
                            'property_id' => $propertyId,
                            'o:label' => $proposition['proposed']['@label'],
                            '@id' => $proposition['proposed']['@uri'],
                            'is_public' => $isPublic,
                            '@language' => $proposition['proposed']['@language'] ?? null,
                        ];
                        break;
                    default:
                        // Nothing.
                        continue 2;
                }
            }
        }

        return $data;
    }

    /**
     * Get generative data (editable, fillable, etc.) via resource template.
     */
    public function generativeData(): \AiGenerator\Mvc\Controller\Plugin\GenerativeData
    {
        static $generative;
        if (!$generative) {
            $generative = $this->getServiceLocator()->get('ControllerPluginManager')
                ->get('generativeData');
            $generative = clone $generative;
            $generative($this->resourceTemplate());
        }
        return $generative;
    }

    /**
     * A generated resource is never public and is managed only by admins and owner.
     *
     * @todo Allow to make generated resource public after submission and validation.
     *
     * This method is added only to simplify views.
     */
    public function isPublic(): bool
    {
        return false;
    }

    /**
     * Check if the proposal matches a resource-like query.
     */
    public function match($query): bool
    {
        if (empty($query)) {
            return true;
        }
        if (!is_array($query)) {
            $query = trim((string) $query, "? \n\t\r");
            $params = [];
            parse_str($query, $params);
            $query = $params;
            unset($params);
        }
        if (empty($query)) {
            return true;
        }

        /** @var \Common\Stdlib\EasyMeta $easyMeta */
        $services = $this->getServiceLocator();
        $easyMeta = $services->get('Common\EasyMeta');
        $propertyIds = $easyMeta->propertyIds();

        $resourceData = $this->proposalToResourceData();

        foreach ($query as $field => $value) {
            if ($value === '' || $value === null || $value === []) {
                continue;
            }

            if ($field === 'resource_template_id') {
                $current = $resourceData['o:resource_template']['o:id'] ?? null;
                $vals = is_array($value) ? $value : [$value];
                if (!in_array($current, $vals)) {
                    return false;
                }
            }

            if ($field === 'resource_class_id') {
                $current = $resourceData['o:resource_class']['o:id'] ?? null;
                $vals = is_array($value) ? $value : [$value];
                if (!in_array($current, $vals)) {
                    return false;
                }
            }

            // TODO Currently, only "eq" is managed.
            if ($field === 'property') {
                if (!is_array($value)) {
                    return false;
                }
                foreach ($value as $propertyQuery) {
                    $prop = $propertyQuery['property'] ?? null;
                    if (empty($prop)) {
                        return false;
                    }
                    if (is_numeric($prop)) {
                        $prop = array_search($prop, $propertyIds);
                        if (!$prop) {
                            return false;
                        }
                    } elseif (!isset($prop, $propertyIds)) {
                        return false;
                    }
                    if (!isset($resourceData[$prop])) {
                        return false;
                    }
                    $text = $propertyQuery['text'];
                    if ($text === '' || $text === null || $text === []) {
                        return false;
                    }
                    $texts = is_array($text) ? array_values($text) : [$text];
                    $resourceDataValues = [];
                    foreach ($resourceData[$prop] as $resourceDataValue) {
                        if (isset($resourceDataValue['@value'])) {
                            $resourceDataValues[] = $resourceDataValue['@value'];
                        }
                        if (isset($resourceDataValue['@id'])) {
                            $resourceDataValues[] = $resourceDataValue['@id'];
                        }
                        if (isset($resourceDataValue['value_resource_id'])) {
                            $resourceDataValues[] = $resourceDataValue['value_resource_id'];
                        }
                        if (isset($resourceDataValue['o:label'])) {
                            $resourceDataValues[] = $resourceDataValue['o:label'];
                        }
                    }
                    // TODO Manage "and".
                    if (!array_intersect($texts, $resourceDataValues)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Get the thumbnail of this resource (the generated one).
     *
     * @return \Omeka\Api\Representation\AssetRepresentation|null
     */
    public function thumbnail()
    {
        $relatedResource = $this->resource();
        return $relatedResource
            ? $relatedResource->thumbnail()
            : null;
    }

    /**
     * Get the title of the generated resource.
     */
    public function title(): string
    {
        $relatedResource = $this->resource();
        return $relatedResource
        ? (string) $relatedResource->getTitle()
            : '';
    }

    /**
     * Get the display title for this generated resource.
     *
     * The title is the resource one if any, else the proposed one.
     */
    public function displayTitle(?string $default = null): ?string
    {
        $relatedResource = $this->resource();
        if ($relatedResource) {
            return $relatedResource->displayTitle($default);
        }

        $template = $this->resourceTemplate();
        $titleTerm = $template && $template->titleProperty()
            ? $template->titleProperty()->term()
            : 'dcterms:title';

        $titles = $this->proposedValues($titleTerm);

        return ($titles ? reset($titles)['proposed']['@value'] ?? null : null)
            ?? (($title = $this->title()) && strlen((string) $title) ? $title : null)
            ?? $default
            ?? $this->getServiceLocator()->get('MvcTranslator')->translate('[Untitled]');
    }

    /**
     * Get all proposal of this generated resource by term with template property.
     *
     * Values of the linked template (media) are not included.
     *
     * @see \Omeka\Api\Representation\AbstractResourceEntityRepresentation::values()
     * @uses \AiGenerator\View\Helper\AiRecordFields
     */
    public function values(): array
    {
        if (isset($this->values)) {
            return $this->values;
        }

        /** @var \AiGenerator\View\Helper\AiRecordFields $aiRecordFields */
        $aiRecordFields = $this->getViewHelper('aiRecordFields');

        // No event triggered for now.

        $relatedResource = $this->resource();
        $this->values = $aiRecordFields($relatedResource, $this);
        return $this->values;
    }

    /**
     * Get the display markup for all values of this resource.
     *
     * Options:
     * - viewName: Name of view script, or a view model. Default
     *   "site/resource-values-generated"
     *
     * @todo Use the same display in show-details and ai-record-list.
     */
    public function displayValues(array $options = []): string
    {
        $options['site'] = $this->getServiceLocator()->get('ControllerPluginManager')->get('currentSite')();
        $options['aiRecord'] = $this;
        $options['resource'] = $this;

        if (!isset($options['viewName'])) {
            $options['viewName'] = 'common/resource-values-generated';
        }

        // No event triggered for now.
        $options['values'] = $this->values();

        $template = $this->resourceTemplate();
        $options['templateProperties'] = $template ? $template->resourceTemplateProperties() : [];

        $partial = $this->getViewHelper('partial');
        return $partial($options['viewName'], $options);
    }

    /**
     * Get an HTML link to a resource (the generated one, not the ai record).
     *
     * @param string $text The text to be linked
     * @param string $action
     * @param array $attributes HTML attributes, key and value
     */
    public function linkResource(string $text, ?string $action = null, array $attributes = []): string
    {
        $relatedResource = $this->resource();
        if (!$relatedResource) {
            return $text;
        }
        $link = $relatedResource->link($text, $action, $attributes);
        // TODO Link to generated resource?
        // When the resource is a new one, go directly to the resource, since
        // the generaed resource is the source of the resource.
        // if (!$this->isPatch()) {
        //     return $link;
        // }
        // TODO Improve the way to append the fragment.
        return preg_replace('~ href="(.+?)"~', ' href="$1#ai-record"', $link, 1);
    }

    /**
     * Get a "pretty" link to this resource containing a thumbnail and
     * display title.
     *
     * @param string $thumbnailType Type of thumbnail to show
     * @param string|null $titleDefault See $default param for displayTitle()
     * @param string|null $action Action to link to (see link() and linkRaw())
     * @param array $attributes HTML attributes, key and value
     */
    public function linkPretty(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ): string {
        $escape = $this->getViewHelper('escapeHtml');
        $thumbnail = $this->getViewHelper('thumbnail');
        $linkContent = sprintf(
            '%s<span class="resource-name">%s</span>',
            $thumbnail($this, $thumbnailType),
            $escape($this->displayTitle($titleDefault))
        );
        if (empty($attributes['class'])) {
            $attributes['class'] = 'resource-link';
        } else {
            $attributes['class'] .= ' resource-link';
        }
        return $this->linkRaw($linkContent, $action, $attributes);
    }

    /**
     * Get a "pretty" link to this resource containing a thumbnail and
     * display title.
     *
     * @param string $thumbnailType Type of thumbnail to show
     * @param string|null $titleDefault See $default param for displayTitle()
     * @param string|null $action Action to link to (see link() and linkRaw())
     * @param array $attributes HTML attributes, key and value
     */
    public function linkPrettyResource(
        $thumbnailType = 'square',
        $titleDefault = null,
        $action = null,
        array $attributes = null
    ): string {
        $relatedResource = $this->resource();
        if (!$relatedResource) {
            return $this->displayTitle($titleDefault);
        }
        $link = $relatedResource->linkPretty($thumbnailType, $titleDefault, $action, $attributes);
        // TODO Improve the way to append the fragment.
        return preg_replace('~ href="(.+?)"~', ' href="$1#ai-record"', $link, 1);
    }

    /**
     * Return the URL to this resource.
     *
     * Unlike parent method, the action is used for the site.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::url()
     */
    public function url($action = null, $canonical = false): ?string
    {
        $status = $this->getServiceLocator()->get('Omeka\Status');
        if ($status->isAdminRequest()) {
            return $this->adminUrl($action, $canonical);
        } else {
            return null;
        }
    }

    /**
     * Get the site url of the current resource.
     */
    public function siteUrlResource($siteSlug = null, $canonical = false, $action = null): ?string
    {
        $relatedResource = $this->resource();
        return $relatedResource
            ? $relatedResource->siteUrl($siteSlug, $canonical, $action)
            : null;
    }

    /**
     * Determine is the generated resource can be validated as resource.
     *
     * A generated resource can be validated if:
     * - not already validated
     * - all propositions are not validated or not marked as "keep".
     *
     * The check does not include user rights.
     *
     * @return bool
     */
    public function isValidable(): bool
    {
        $resourceValidable = false;
        $proposal = $this->proposalNormalizeForValidation();
        // Clean data for the special keys.
        unset($proposal['template'], $proposal['media']);
        foreach ($proposal as $propositions) foreach ($propositions as $proposition) {
            $resourceValidable = $resourceValidable
                || (!$proposition['validated'] && $proposition['process'] !== 'keep');
        }
        return $resourceValidable;
    }

    /**
     * Get the list of uris and labels of a specific custom vocab.
     *
     * @see \AiGenerator\Controller\Admin\IndexController::customVocabUriLabels()
     * @see \AiGenerator\Api\Representation\AiRecordRepresentation::customVocabUriLabels()
     *
     * @todo Use EasyMeta or CustomVocab directly.
     */
    protected function customVocabUriLabels(string $dataType): array
    {
        static $uriLabels = [];
        if (!isset($uriLabels[$dataType])) {
            $uriLabels[$dataType] = [];
            $customVocabId = (int) substr($dataType, 12);
            if ($customVocabId) {
                $api = $this->getServiceLocator()->get('Omeka\ApiManager');
                try {
                    /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
                    $customVocab = $api->read('custom_vocabs', ['id' => $customVocabId])->getContent();
                    $uriLabels[$customVocabId] = $customVocab->listUriLabels() ?: [];
                } catch (\Exception $e) {
                    // Skip.
                }
            }
        }
        return $uriLabels[$customVocabId];
    }
}
