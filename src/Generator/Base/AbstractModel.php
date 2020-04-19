<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator\Base;

use Randock\Graphql\Generator\Base\Exception\FieldNotSelectedException;

class AbstractModel
{
    /**
     * @var array
     */
    protected $data;

    /**
     * Order constructor.
     *
     * @param array|null $data
     */
    protected function __construct(?array $data)
    {
        $this->data = null !== $data ? $data : [];
    }

    /**
     * @param string $field
     * @param bool   $nullable
     *
     * @throws FieldNotSelectedException
     *
     * @return mixed
     */
    protected function _getField(string $field, bool $nullable)
    {
        if (!\array_key_exists($field, $this->data)) {
            throw new FieldNotSelectedException(
                \sprintf(
                    'The field %s has not been selected in the query. Add it to the query if you need it.',
                    $field
                )
            );
        }

        return $this->data[$field];
    }
}
