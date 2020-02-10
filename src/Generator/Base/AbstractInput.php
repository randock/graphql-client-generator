<?php

declare(strict_types=1);

namespace Randock\Graphql\Generator\Base;

class AbstractInput extends AbstractModel
{
    /**
     * Order constructor.
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        parent::__construct($data);
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
