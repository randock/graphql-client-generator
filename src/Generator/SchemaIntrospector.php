<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator;

use Softonic\GraphQL\Client;

class SchemaIntrospector
{
    /**
     * @var Client
     */
    private $graphqlClient;

    /**
     * SchemaIntrospector constructor.
     *
     * @param Client $graphqlClient
     */
    public function __construct(Client $graphqlClient)
    {
        $this->graphqlClient = $graphqlClient;
    }

    /**
     * @return array
     */
    public function getTypes(string $kind): array
    {
        $query = <<< 'GRAPHQL'
{
  __schema {
    types {
      name
      kind
    }
  }
}
GRAPHQL;

        $response = $this->graphqlClient->query(
            $query
        );

        $objects = [];
        foreach ($response->getData()['__schema']['types'] as $object) {
            if ($kind === $object['kind'] && !\preg_match('/^\_\_/', $object['name']) && 'Query' !== $object['name']) {
                $objects[] = $object['name'];
            }
        }

        return $objects;
    }

    public function getObject(string $class)
    {
        $query = <<<'GRAPHQL'
{
 __type(name: "%s") {
    name
    kind
    fields{
      name
      description
      type{
          name
          kind
          description
          ofType{
            name
            kind
            ofType{
              name
              kind
              ofType{
                name
                kind
              }
            }
          }
        }
    }
  }
}
GRAPHQL;

        $response = $this->graphqlClient->query(
            \sprintf(
                $query,
                $class
            )
        );

        return $response->getData()['__type'];
    }

    /**
     * @param string $objectName
     *
     * @return array
     */
    public function getInputObject(string $objectName): array
    {
        $schemaQuery = <<<'GRAPHQL'
{
    __type(name: "%s") {
    name
    kind
    inputFields {
      name
      description
      defaultValue
      type{
          name
          kind
          description
          ofType{
            name
            kind
            ofType{
              name
              kind
              ofType{
                name
                kind
              }
            }
          }
        }
      }
    }
}
GRAPHQL;

        $response = $this->graphqlClient->query(
            \sprintf(
                $schemaQuery,
                $objectName
            )
        );

        return $response->getData()['__type'];
    }

    /**
     * @param string $objectName
     *
     * @return array
     */
    public function getEnumObject(string $objectName): array
    {
        $schemaQuery = <<<'GRAPHQL'
{
  __type(name: "%s") {
    name
    kind
    enumValues {
      name
      description
    }
  }
}
GRAPHQL;

        $response = $this->graphqlClient->query(
            \sprintf(
                $schemaQuery,
                $objectName
            )
        );

        return $response->getData()['__type'];
    }

    public function getQuery()
    {
        $schemaQuery = <<<'GRAPHQL'
{
  __schema {
    queryType {
      name
      kind
      description
      fields {
        name
        description
        type {
          name
          kind
          description
          ofType {
            name
            kind
            ofType {
              name
              kind
              ofType {
                name
                kind
              }
            }
          }
        }
        args {
          name
          description
          defaultValue
          type {
            name
            kind
            description
            ofType {
              name
              kind
              ofType {
                name
                kind
                ofType {
                  name
                  kind
                }
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

        $response = $this->graphqlClient->query(
            $schemaQuery
        );

        return $response->getData()['__schema']['queryType'];
    }
}
