<?php

namespace BitWasp\Bitcoin\Node\Chain;

use BitWasp\Buffertools\BufferInterface;

interface ChainCacheInterface extends \Countable
{
    /**
     * @param BufferInterface $hash
     * @return bool
     */
    public function containsHash(BufferInterface $hash);

    /**
     * @param BufferInterface $hash
     * @return int
     */
    public function getHeight(BufferInterface $hash);

    /**
     * @param int $height
     * @throws \RuntimeException
     * @return BufferInterface
     */
    public function getHash($height);

    /**
     * @param BlockIndexInterface $index
     */
    public function add(BlockIndexInterface $index);
}
