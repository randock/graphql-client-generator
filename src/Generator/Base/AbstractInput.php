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
        return \array_map(function ($item) {
            if (\is_object($item)) {
                if ($item instanceof AbstractEnum) {
                    return $item->getValue();
                } elseif ($item instanceof self) {
                    return $item->toArray();
                }
            }

            return $item;
        }, $this->data);
    }
}
