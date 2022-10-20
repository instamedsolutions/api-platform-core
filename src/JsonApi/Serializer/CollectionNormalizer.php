<?php

/*
 * This file is part of the API Platform project.
 *
 * (c) Kévin Dunglas <dunglas@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ApiPlatform\Core\JsonApi\Serializer;

use ApiPlatform\Core\Api\ResourceClassResolverInterface;
use ApiPlatform\Core\Metadata\Resource\Factory\ResourceMetadataFactoryInterface;
use ApiPlatform\Core\Serializer\AbstractCollectionNormalizer;
use ApiPlatform\Core\Util\IriHelper;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * Normalizes collections in the JSON API format.
 *
 * @author Kevin Dunglas <dunglas@gmail.com>
 * @author Hamza Amrouche <hamza@les-tilleuls.coop>
 * @author Baptiste Meyer <baptiste.meyer@gmail.com>
 */
final class CollectionNormalizer extends AbstractCollectionNormalizer
{
    public const FORMAT = 'jsonapi';

    private $resourceMetadataFactory;

    private $propertyAccessor;

    public function __construct(
        ResourceClassResolverInterface $resourceClassResolver,
        ResourceMetadataFactoryInterface $resourceMetadataFactory,
        PropertyAccessorInterface $propertyAccessor,
        string $pageParameterName)
    {
        $this->propertyAccessor = $propertyAccessor;
        $this->resourceMetadataFactory = $resourceMetadataFactory;

        parent::__construct($resourceClassResolver, $pageParameterName);
    }

    /**
     * {@inheritdoc}
     */
    protected function getPaginationData($object, array $context = []): array
    {
        [$paginator, $paginated, $currentPage, $itemsPerPage, $lastPage, $pageTotalItems, $totalItems] = $this->getPaginationConfig($object, $context);
        $parsed = IriHelper::parseIri($context['request_uri'] ?? '/', $this->pageParameterName);

        $data = [
            'links' => [
                'self' => IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $paginated ? $currentPage : null),
            ],
        ];

        $metadata = isset($context['resource_class']) && null !== $this->resourceMetadataFactory ? $this->resourceMetadataFactory->create($context['resource_class']) : null;
        $isPaginatedWithCursor = $paginated && null !== $metadata && null !== $cursorPaginationAttribute = $metadata->getCollectionOperationAttribute($context['collection_operation_name'] ?? $context['subresource_operation_name'], 'pagination_via_cursor', null, true);

        if ($isPaginatedWithCursor) {
            $objects = iterator_to_array($object);
            $firstObject = current($objects);
            $lastObject = end($objects);

            $data['hydra:view']['self'] = IriHelper::createIri($parsed['parts'], $parsed['parameters']);

            if ($firstObject && 1. !== $currentPage) {
                $data['links']['prev'] = IriHelper::createIri($parsed['parts'], array_merge($parsed['parameters'],$this->cursorPaginationFields($cursorPaginationAttribute, -1, $firstObject)));
            }

            if ($lastObject && null !== $lastPage && $currentPage !== $lastPage || null === $lastPage && $pageTotalItems >= $itemsPerPage) {
                $data['links']['next'] = IriHelper::createIri($parsed['parts'], array_merge($parsed['parameters'],$this->cursorPaginationFields($cursorPaginationAttribute, 1, $lastObject)));
            }
        }
        elseif ($paginated) {
            if (null !== $lastPage) {
                $data['links']['first'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, 1.);
                $data['links']['last'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $lastPage);
            }

            if (1. !== $currentPage) {
                $data['links']['prev'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $currentPage - 1.);
            }

            if (null !== $lastPage && $currentPage !== $lastPage || null === $lastPage && $pageTotalItems >= $itemsPerPage) {
                $data['links']['next'] = IriHelper::createIri($parsed['parts'], $parsed['parameters'], $this->pageParameterName, $currentPage + 1.);
            }
        }

        if (null !== $totalItems) {
            $data['meta']['totalItems'] = $totalItems;
        }

        if ($paginator) {
            $data['meta']['itemsPerPage'] = (int) $itemsPerPage;
            $data['meta']['currentPage'] = (int) $currentPage;
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     *
     * @throws UnexpectedValueException
     */
    protected function getItemsData($object, string $format = null, array $context = []): array
    {
        $data = [
            'data' => [],
        ];

        foreach ($object as $obj) {
            $item = $this->normalizer->normalize($obj, $format, $context);
            if (!\is_array($item)) {
                throw new UnexpectedValueException('Expected item to be an array');
            }

            if (!isset($item['data'])) {
                throw new UnexpectedValueException('The JSON API document must contain a "data" key.');
            }

            $data['data'][] = $item['data'];

            if (isset($item['included'])) {
                $data['included'] = array_values(array_unique(array_merge($data['included'] ?? [], $item['included']), SORT_REGULAR));
            }
        }

        return $data;
    }



    private function cursorPaginationFields(array $fields, int $direction, $object)
    {
        $paginationFilters = [];


        foreach ($fields as $field) {
            $forwardRangeOperator = 'desc' === strtolower($field['direction']) ? 'lt' : 'gt';
            $backwardRangeOperator = 'gt' === $forwardRangeOperator ? 'lt' : 'gt';

            $operator = $direction > 0 ? $forwardRangeOperator : $backwardRangeOperator;

            $paginationFilters[$field['field']] = [
                $operator => (string) $this->propertyAccessor->getValue($object, $field['field']),
            ];
        }

        return $paginationFilters;
    }

}
