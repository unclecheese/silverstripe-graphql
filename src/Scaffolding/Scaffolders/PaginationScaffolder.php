<?php

namespace SilverStripe\GraphQL\Scaffolding\Scaffolders;

use GraphQL\Error\Error;
use SilverStripe\GraphQL\Manager;
use SilverStripe\GraphQL\Pagination\Connection;
use SilverStripe\GraphQL\Pagination\PaginatedQueryCreator;
use SilverStripe\GraphQL\Resolvers\PaginationResolverFactory;
use SilverStripe\GraphQL\Scaffolding\Interfaces\ManagerMutatorInterface;
use SilverStripe\GraphQL\Scaffolding\Interfaces\ScaffolderInterface;
use Psr\Container\NotFoundExceptionInterface;

class PaginationScaffolder extends PaginatedQueryCreator implements ManagerMutatorInterface, ScaffolderInterface
{
    /**
     * @var string
     */
    protected $operationName;

    /**
     * @param string $operationName
     * @param Manager $manager
     * @param  Connection $connection
     */
    public function __construct($operationName, Manager $manager, Connection $connection)
    {
        parent::__construct($manager);
        $this->connection = $connection;
        $this->operationName = $operationName;
    }

    /**
     * Connection is passed in through the constructor argument,
     * to allow the instance to be created by the external scaffolding logic.
     * @return Connection
     */
    public function createConnection()
    {
        return $this->connection;
    }

    /**
     * @return string
     */
    public function getOperationName()
    {
        return $this->operationName;
    }

    /**
     * @param string $name
     * @return $this
     */
    public function setOperationName($name)
    {
        $this->operationName = $name;

        return $this;
    }

    /**
     * @param Manager $manager
     * @throws Error
     * @return array
     */
    public function scaffold(Manager $manager)
    {
        $conn = $this->connection;
        $connectionName = $conn->getConnectionTypeName();
        $factory = PaginationResolverFactory::create([
            'parentResolver' => $conn->getConnectionResolver(),
            'parentResolverFactory' => $conn->getResolverFactory(),
            'defaultLimit' => $conn->getDefaultLimit(),
            'maximumLimit' => $conn->getMaximumLimit(),
            'sortableFields' => $conn->getSortableFields()
        ]);

        return [
            'name' => $this->operationName,
            'args' => $conn->args(),
            'type' => $manager->getType($connectionName),
            'resolverFactory' => $factory,
            'resolve' => $factory->createClosure(),
        ];
    }

    /**
     * @param Manager $manager
     * @throws NotFoundExceptionInterface
     */
    public function addToManager(Manager $manager)
    {
        $manager->addType($this->connection->toType());
        foreach ($this->connection->extraTypes() as $type) {
            $manager->addType($type);
        }
    }
}
