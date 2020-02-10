<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator\Base;

use Softonic\GraphQL\Client;
use Softonic\GraphQL\Response;
use Randock\Graphql\Generator\Base\Exception\RequestException;

abstract class ApiClient
{
    /**
     * @var Client
     */
    private $graphqlClient;

    /**
     * ApiClient constructor.
     *
     * @param Client $graphqlClient
     */
    public function __construct(Client $graphqlClient)
    {
        $this->graphqlClient = $graphqlClient;
    }

    /**
     * @param string $query
     * @param array  $arguments
     *
     * @throws RequestException
     *
     * @return Response
     */
    public function query(string $query, array $arguments = []): Response
    {
        $result = $this->graphqlClient->query(
            $query,
            $arguments
        );

        if ($result->hasErrors()) {
            throw new RequestException(
                $result->getErrors()
            );
        }

        return $result;
    }

    /**
     * @return array
     */
    public function paging(): array
    {
        return [
            'paging' => [
                'total',
                'page',
                'pages',
                'limit',
            ],
        ];
    }

    /**
     * @param mixed $item
     *
     * @return mixed
     */
    protected function convertInput($item)
    {
        if (\is_object($item)) {
            if ($item instanceof AbstractEnum) {
                return $item->getValue();
            } elseif ($item instanceof AbstractInput) {
                return $this->convertInput(
                    $item->toArray()
                );
            }
        } elseif (\is_array($item)) {
            return \array_map([$this, 'convertInput'], $item);
        }

        return $item;
    }

    /**
     * @param array $fields
     *
     * @return string
     */
    protected function convertFields(array $fields): string
    {
        $graphql = '';
        foreach ($fields as $key => $value) {
            if (\is_string($value)) {
                $graphql .= ' ';
                $graphql .= $value;
            } elseif (\is_array($value)) {
                $graphql .= ' ';
                if ('__parameters' === $key) {
                    continue;
                }
                if ('__' === \substr($key, 0, 2)) {
                    $graphql .= '... on ' . \substr($key, 2);
                    $graphql .= '{ __typename ';
                } else {
                    $graphql .= $key;
                    if (isset($value['__parameters'])) {
                        $graphql .= '(';
                        $params = [];
                        foreach ($value['__parameters'] as $param => $paramValue) {
                            $params[] = \sprintf(
                                '%s: %s',
                                $param,
                                \is_int($paramValue) ? $paramValue : \json_encode($paramValue)
                            );
                        }
                        $graphql .= \implode(',', $params);
                        $graphql .= ')';
                    }
                    $graphql .= '{';
                }
                $graphql .= $this->convertFields($value);
                $graphql .= '}';
            }
        }

        return \trim($graphql);
    }
}
