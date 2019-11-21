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
     * @param $input
     *
     * @return array
     */
    protected function convertInput($input)
    {
        $convert = function ($input) {
            if (\is_object($input) && $input instanceof AbstractInput) {
                return $input->toArray();
            }

            return $input;
        };

        if (\is_array($input)) {
            return \array_map(
                $convert,
                $input
            );
        }

        return $convert($input);
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
            $graphql .= ' ';
            if (\is_string($value)) {
                $graphql .= $value;
            } elseif (\is_array($value)) {
                $graphql .= $key;
                $graphql .= '{';
                $graphql .= $this->convertFields($value);
                $graphql .= '}';
            }
        }

        return \trim($graphql);
    }
}
