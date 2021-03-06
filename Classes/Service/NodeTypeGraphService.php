<?php
declare(strict_types=1);

namespace Shel\ContentRepository\Debugger\Service;

use Doctrine\ORM\EntityManagerInterface;
use Neos\Cache\Exception;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class NodeTypeGraphService
{

    /**
     * @Flow\Inject
     * @var NodeTypeManager
     */
    protected $nodeTypeManager;

    /**
     * @Flow\InjectConfiguration("defaults")
     * @var array
     */
    protected $defaults;

    /**
     * @Flow\Inject
     * @var VariableFrontend
     */
    protected $nodeTypesCache;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var StringFrontend
     */
    protected $configurationCache;

    /**
     * @return array
     */
    public function generateNodeTypesData(): array
    {
        $nodeTypesCacheKey = 'NodeTypes_' . $this->configurationCache->get('ConfigurationVersion');

        $nodeTypes = $this->nodeTypesCache->get($nodeTypesCacheKey);

        if ($nodeTypes) {
            return $nodeTypes;
        }

        $nodeTypes = $this->nodeTypeManager->getNodeTypes();
        $nodeTypeUsage = $this->getNodeTypeUsageQuery();

        $defaultConfiguration = ['superTypes' => []];

        $nodeTypes = array_reduce($nodeTypes,
            function (array $carry, NodeType $nodeType) use ($defaultConfiguration, $nodeTypes, $nodeTypeUsage) {
                $nodeTypeName = $nodeType->getName();
                $carry[$nodeTypeName] = [
                    'name' => $nodeTypeName,
                    'abstact' => $nodeType->isAbstract(),
                    'final' => $nodeType->isFinal(),
                    'configuration' => array_merge($defaultConfiguration, $nodeType->getFullConfiguration()),
                    'declaredSuperTypes' => array_reduce($nodeType->getDeclaredSuperTypes(),
                        static function (array $carry, NodeType $superType) {
                            $carry[] = $superType->getName();
                            return $carry;
                        }, []),
                    'usageCount' => array_key_exists($nodeTypeName,
                        $nodeTypeUsage) ? (int)$nodeTypeUsage[$nodeTypeName] : 0,
                ];

                $instantiableNodeTypes = array_filter($nodeTypes, static function (NodeType $nodeType) {
                    return !$nodeType->isAbstract();
                });
                $carry[$nodeTypeName]['allowedChildNodeTypes'] = $this->generateAllowedChildNodeTypes($nodeType,
                    $instantiableNodeTypes);

                if (array_key_exists('childNodes', $carry[$nodeTypeName]['configuration'])) {
                    foreach (array_keys($carry[$nodeTypeName]['configuration']['childNodes']) as $childNodeName) {
                        $carry[$nodeTypeName]['configuration']['childNodes'][$childNodeName]['allowedChildNodeTypes'] = $this->generateAllowedGrandChildNodeTypes($childNodeName,
                            $nodeType, $instantiableNodeTypes);
                    }
                }

                return $carry;
            }, []);

        $this->nodeTypesCache->flush();
        try {
            $this->nodeTypesCache->set($nodeTypesCacheKey, $nodeTypes);
        } catch (Exception $e) {
            // TODO: Log cache issue
        }

        return $nodeTypes;
    }

    /**
     * Return the usage count of each nodetype in the content repository
     *
     * @return array
     */
    public function getNodeTypeUsageQuery(): array
    {
        $qb = $this->entityManager->createQueryBuilder();
        $nodeTypeUsage = $qb->select('n.nodeType, COUNT(n.identifier) as count')
            ->from(NodeData::class, 'n')
            ->groupBy('n.nodeType')
            ->andWhere('n.removed = false')
            ->getQuery()
            ->getScalarResult();

        $nodeTypes = array_column($nodeTypeUsage, 'nodeType');
        $usageCount = array_column($nodeTypeUsage, 'count');

        return array_combine($nodeTypes, $usageCount);
    }

    /**
     * Returns the list of all allowed subnodetypes of the given node
     *
     * @param NodeType $baseNodeType
     * @param array $nodeTypes
     * @return array
     */
    public function generateAllowedChildNodeTypes(NodeType $baseNodeType, array $nodeTypes): array
    {
        $childNodeTypes = array_reduce($nodeTypes, function (array $carry, NodeType $nodeType) use ($baseNodeType) {
            if ($baseNodeType->allowsChildNodeType($nodeType)) {
                $carry[] = $nodeType->getName();
            }
            return $carry;
        }, []);
        sort($childNodeTypes);
        return $childNodeTypes;
    }

    /**
     * Returns the list of all allowed subnodetypes of the given nodes child
     *
     * @param string $childName
     * @param NodeType $baseNodeType
     * @param array $nodeTypes
     * @return array
     */
    public function generateAllowedGrandChildNodeTypes(
        string $childName,
        NodeType $baseNodeType,
        array $nodeTypes
    ): array {
        return array_reduce($nodeTypes, function (array $carry, NodeType $nodeType) use ($baseNodeType, $childName) {
            try {
                if ($baseNodeType->allowsGrandchildNodeType($childName, $nodeType)) {
                    $carry[] = $nodeType->getName();
                }
            } catch (\InvalidArgumentException $e) {
                // Skip non autogenerated child nodes
            }
            return $carry;
        }, []);
    }
}
