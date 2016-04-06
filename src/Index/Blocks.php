<?php

namespace BitWasp\Bitcoin\Node\Index;

use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Node\Chain\BlockData;
use BitWasp\Bitcoin\Node\Chain\BlockIndexInterface;
use BitWasp\Bitcoin\Node\Chain\ChainsInterface;
use BitWasp\Bitcoin\Node\Chain\UtxoView;
use BitWasp\Bitcoin\Node\Consensus;
use BitWasp\Bitcoin\Node\DbInterface;
use BitWasp\Bitcoin\Node\HashStorage;
use BitWasp\Bitcoin\Node\Index\Validation\BlockCheck;
use BitWasp\Bitcoin\Node\Index\Validation\BlockCheckInterface;
use BitWasp\Bitcoin\Node\Index\Validation\Forks;
use BitWasp\Bitcoin\Node\Index\Validation\ScriptValidation;
use BitWasp\Bitcoin\Node\Serializer\Block\CachingBlockSerializer;
use BitWasp\Bitcoin\Node\Serializer\Transaction\CachingTransactionSerializer;
use BitWasp\Bitcoin\Script\Interpreter\InterpreterInterface;
use BitWasp\Bitcoin\Serializer\Block\BlockHeaderSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializerInterface;
use BitWasp\Bitcoin\Transaction\OutPoint;
use BitWasp\Bitcoin\Utxo\Utxo;
use BitWasp\Buffertools\BufferInterface;
use Evenement\EventEmitter;
use Packaged\Config\ConfigProviderInterface;

class Blocks extends EventEmitter
{

    /**
     * @var DbInterface
     */
    private $db;

    /**
     * @var \BitWasp\Bitcoin\Math\Math
     */
    private $math;

    /**
     * @var ConfigProviderInterface
     */
    private $config;

    /**
     * @var BlockCheckInterface
     */
    private $blockCheck;

    /**
     * @var ChainsInterface
     */
    private $chains;

    /**
     * @var Forks
     */
    private $forks;

    /**
     * @var Consensus
     */
    private $consensus;

    /**
     * Blocks constructor.
     * @param DbInterface $db
     * @param ConfigProviderInterface $config
     * @param EcAdapterInterface $ecAdapter
     * @param ChainsInterface $chains
     * @param Consensus $consensus
     */
    public function __construct(
        DbInterface $db,
        ConfigProviderInterface $config,
        EcAdapterInterface $ecAdapter,
        ChainsInterface $chains,
        Consensus $consensus
    ) {

        $this->db = $db;
        $this->config = $config;
        $this->math = $ecAdapter->getMath();
        $this->chains = $chains;
        $this->consensus = $consensus;
        $this->blockCheck = new BlockCheck($consensus, $ecAdapter);
    }

    /**
     * @param BlockInterface $genesisBlock
     */
    public function init(BlockInterface $genesisBlock)
    {
        $hash = $genesisBlock->getHeader()->getHash();
        $index = $this->db->fetchIndex($hash);

        try {
            $this->db->fetchBlock($hash);
        } catch (\Exception $e) {
            $this->db->insertToBlockIndex($index->getHash());
        }
    }

    /**
     * @param BufferInterface $hash
     * @return BlockInterface
     */
    public function fetch(BufferInterface $hash)
    {
        return $this->db->fetchBlock($hash);
    }

    /**
     * @param BlockInterface $block
     * @param TransactionSerializerInterface $txSerializer
     * @return BlockData
     */
    public function parseUtxos(BlockInterface $block, TransactionSerializerInterface $txSerializer)
    {
        $blockData = new BlockData();
        $unknown = [];
        $hashStorage = new HashStorage();

        // Record every Outpoint required for the block.
        foreach ($block->getTransactions() as $t => $tx) {
            if ($tx->isCoinbase()) {
                continue;
            }

            foreach ($tx->getInputs() as $in) {
                $outpoint = $in->getOutPoint();
                $unknown[$outpoint->getTxId()->getBinary() . $outpoint->getVout()] = $outpoint;
            }
        }

        foreach ($block->getTransactions() as $tx) {
            /** @var BufferInterface $buffer */
            $buffer = $txSerializer->serialize($tx);
            $hash = Hash::sha256d($buffer)->flip();
            $hashStorage->attach($tx, $hash);
            $hashBin = $hash->getBinary();
            foreach ($tx->getOutputs() as $i => $out) {
                $lookup = $hashBin . $i;
                if (isset($unknown[$lookup])) {
                    // Remove unknown outpoints which consume this output
                    $outpoint = $unknown[$lookup];
                    $utxo = new Utxo($outpoint, $out);
                    unset($unknown[$lookup]);
                } else {
                    // Record new utxos which are not consumed in the same block
                    $utxo = new Utxo(new OutPoint($hash, $i), $out);
                    $blockData->remainingNew[] = $utxo;
                }

                // All utxos produced are stored
                $blockData->parsedUtxos[] = $utxo;
            }
        }

        $blockData->requiredOutpoints = array_values($unknown);
        $blockData->hashStorage = $hashStorage;
        return $blockData;
    }

    /**
     * @param BlockInterface $block
     * @param TransactionSerializerInterface $txSerializer
     * @return BlockData
     */
    public function prepareBatch(BlockInterface $block, TransactionSerializerInterface $txSerializer)
    {
        $blockData = $this->parseUtxos($block, $txSerializer);

        if ($this->config->getItem('config', 'index_utxos', true)) {
            $remaining = $this->db->fetchUtxoDbList($blockData->requiredOutpoints);
        } else {
            $remaining = $this->db->fetchUtxoList($block->getHeader()->getPrevBlock(), $blockData->requiredOutpoints);
        }

        $blockData->utxoView = new UtxoView(array_merge($remaining, $blockData->parsedUtxos));
        return $blockData;
    }

    /**
     * @param BlockInterface $block
     * @param Headers $headers
     * @param bool $checkSignatures
     * @param bool $checkMerkleRoot
     * @param bool $checkSize
     * @return BlockIndexInterface
     */
    public function accept(BlockInterface $block, Headers $headers, $checkSignatures = true, $checkSize = true, $checkMerkleRoot = true)
    {
        $state = $this->chains->best();

        $hash = $block->getHeader()->getHash();
        $index = $headers->accept($hash, $block->getHeader());

        $txSerializer = new CachingTransactionSerializer();
        $blockSerializer = new CachingBlockSerializer($this->math, new BlockHeaderSerializer(), $txSerializer);

        $blockData = $this->prepareBatch($block, $txSerializer);

        $v = microtime(true);
        $this
            ->blockCheck
            ->check($block, $txSerializer, $blockSerializer, $checkSize, $checkMerkleRoot)
            ->checkContextual($block, $state->getLastBlock());

        $view = $blockData->utxoView;

        if ($this->forks instanceof Forks && $this->forks->isNext($index)) {
            $forks = $this->forks;
        } else {
            $versionInfo = $this->db->findSuperMajorityInfoByHash($block->getHeader()->getPrevBlock());
            $forks = $this->forks = new Forks($this->consensus->getParams(), $state->getLastBlock(), $versionInfo);
        }

        $flags = $forks->getFlags();
        $scriptCheckState = new ScriptValidation($checkSignatures, $flags);

        $nFees = 0;
        $nSigOps = 0;

        foreach ($block->getTransactions() as $tx) {
            $nSigOps += $this->blockCheck->getLegacySigOps($tx);

            if ($nSigOps > $this->consensus->getParams()->getMaxBlockSigOps()) {
                throw new \RuntimeException('Blocks::accept() - too many sigops');
            }

            if (!$tx->isCoinbase()) {
                if ($flags & InterpreterInterface::VERIFY_P2SH) {
                    $nSigOps = $this->blockCheck->getP2shSigOps($view, $tx);
                    if ($nSigOps > $this->consensus->getParams()->getMaxBlockSigOps()) {
                        throw new \RuntimeException('Blocks::accept() - too many sigops');
                    }
                }

                $fee = $this->math->sub($view->getValueIn($this->math, $tx), $tx->getValueOut());
                $nFees = $this->math->add($nFees, $fee);

                $this->blockCheck->checkInputs($view, $tx, $index->getHeight(), $flags, $scriptCheckState);
            }
        }

        if ($scriptCheckState->active() && !$scriptCheckState->result()) {
            throw new \RuntimeException('ScriptValidation failed!');
        }

        $this->blockCheck->checkCoinbaseSubsidy($block->getTransaction(0), $nFees, $index->getHeight());
        echo "Validation: " . (microtime(true) - $v) . " seconds\n";

        $m = microtime(true);
        $this->db->transaction(function () use ($hash, $state, $block, $blockData, $blockSerializer) {
            $blockId = $this->db->insertBlock($hash, $block, $blockSerializer);

            if ($this->config->getItem('config', 'index_utxos', true)) {
                $this->emit('block', [$state, $block, $blockData]);
            }

            if ($this->config->getItem('config', 'index_transactions', true)) {
                $this->db->insertBlockTransactions($blockId, $block, $blockData->hashStorage);
            }
        });
        echo "Block insert: ".(microtime(true)-$m) . " seconds\n";

        $state->updateLastBlock($index);
        $this->forks->next($index);

        return $index;
    }
}
