<?php

namespace BitWasp\Bitcoin\Node\Index;

use BitWasp\Bitcoin\Bitcoin;
use BitWasp\Bitcoin\Block\BlockInterface;
use BitWasp\Bitcoin\Crypto\EcAdapter\Adapter\EcAdapterInterface;
use BitWasp\Bitcoin\Crypto\Hash;
use BitWasp\Bitcoin\Node\Chain\BlockIndexInterface;
use BitWasp\Bitcoin\Node\Chain\ChainsInterface;
use BitWasp\Bitcoin\Node\Chain\HeaderChainViewInterface;
use BitWasp\Bitcoin\Node\Chain\UtxoSet;
use BitWasp\Bitcoin\Node\Chain\UtxoView;
use BitWasp\Bitcoin\Node\Consensus;
use BitWasp\Bitcoin\Node\Db\DbInterface;
use BitWasp\Bitcoin\Node\HashStorage;
use BitWasp\Bitcoin\Node\Index\Validation\BlockAcceptData;
use BitWasp\Bitcoin\Node\Index\Validation\BlockCheck;
use BitWasp\Bitcoin\Node\Index\Validation\BlockCheckInterface;
use BitWasp\Bitcoin\Node\Index\Validation\BlockData;
use BitWasp\Bitcoin\Node\Index\Validation\Forks;
use BitWasp\Bitcoin\Node\Index\Validation\ScriptValidation;
use BitWasp\Bitcoin\Node\Serializer\Block\CachingBlockSerializer;
use BitWasp\Bitcoin\Node\Serializer\Transaction\CachingOutPointSerializer;
use BitWasp\Bitcoin\Node\Serializer\Transaction\CachingTransactionSerializer;
use BitWasp\Bitcoin\Script\Interpreter\InterpreterInterface;
use BitWasp\Bitcoin\Serializer\Block\BlockHeaderSerializer;
use BitWasp\Bitcoin\Serializer\Block\BlockSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\OutPointSerializerInterface;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionInputSerializer;
use BitWasp\Bitcoin\Serializer\Transaction\TransactionSerializer;
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
            echo $e->getMessage().PHP_EOL;
            $this->db->insertBlock($index->getHash(), $genesisBlock, new BlockSerializer(Bitcoin::getMath(), new BlockHeaderSerializer(), new TransactionSerializer()), BlockStatus::VALIDATED);
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
    public function parseUtxos(BlockInterface $block, TransactionSerializerInterface $txSerializer, OutPointSerializerInterface $oSerializer)
    {
        $v = [];
        $packV = function ($n) use (&$v) {
            if (array_key_exists($n, $v)) {
                return $v[$n];
            }
            $s = pack("V", $n);
            $v[$n]= $s;
            return $s;
        };

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
                $outpointKey = "{$outpoint->getTxId()->getBinary()}{$packV($outpoint->getVout())}";
                $unknown[$outpointKey] = $outpoint;
            }
        }

        $bytesHashed = 0;

        foreach ($block->getTransactions() as $tx) {
            /** @var BufferInterface $buffer */
            $buffer = $txSerializer->serialize($tx);
            $bytesHashed += $buffer->getSize();

            $txid = Hash::sha256d($buffer)->flip();
            $hashStorage->attach($tx, $txid);
            $hashBin = $txid->getBinary();

            foreach ($tx->getOutputs() as $i => $out) {
                $lookup = "{$hashBin}{$packV($i)}";
                if (isset($unknown[$lookup])) {
                    // Remove unknown outpoints which consume this output
                    $outpoint = $unknown[$lookup];
                    $utxo = new Utxo($outpoint, $out);
                    unset($unknown[$lookup]);
                } else {
                    // Record new utxos which are not consumed in the same block
                    $utxo = new Utxo(new OutPoint($txid, $i), $out);
                    $blockData->remainingNew[$lookup] = $utxo;
                }

                // All utxos produced are stored
                $blockData->parsedUtxos[$lookup] = $utxo;
            }
        }

        echo "Prepare: {$bytesHashed} bytes hashed\n";
        $blockData->requiredOutpoints = $unknown;
        $blockData->hashStorage = $hashStorage;

        return $blockData;
    }

    /**
     * @param BlockInterface $block
     * @param TransactionSerializerInterface $txSerializer
     * @param UtxoSet $utxoSet
     * @return BlockData
     */
    public function prepareBatch(BlockInterface $block, TransactionSerializerInterface $txSerializer, OutPointSerializerInterface $oSerializer, UtxoSet $utxoSet)
    {
        $parseBegin = microtime(true);
        $blockData = $this->parseUtxos($block, $txSerializer, $oSerializer);
        $parseDiff = microtime(true)-$parseBegin;
        $selectBegin = microtime(true);
        $blockData->utxoView = new UtxoView(array_merge(
            $utxoSet->fetchView($blockData),
            $blockData->parsedUtxos
        ));
        $selectDiff = microtime(true)-$selectBegin;
        echo "UTXOS: [parse {$parseDiff}] [select: {$selectDiff}]\n";
        
        return $blockData;
    }

    /**
     * @param HeaderChainViewInterface $headersView
     * @param BlockIndexInterface $index
     * @return array
     */
    public function prepareForks(HeaderChainViewInterface $headersView, BlockIndexInterface $index)
    {
        if ($this->forks instanceof Forks && $this->forks->isNext($index)) {
            $forks = $this->forks;
            $wasNext = true;
        } else {
            $versionInfo = $this->db->findSuperMajorityInfoByHash($index->getHeader()->getPrevBlock());
            $forks = new Forks($this->consensus->getParams(), $headersView->validBlocks()->getIndex(), $versionInfo);
            $wasNext = false;
        }

        return [$wasNext, $forks];
    }

    /**
     * @param BlockInterface $block
     * @param BlockData $blockData
     * @param bool $checkSignatures
     * @param int $flags
     * @param int $height
     * @param TransactionSerializerInterface $txSerializer
     */
    public function checkBlockData(BlockInterface $block, BlockData $blockData, $checkSignatures, $flags, $height, TransactionSerializerInterface $txSerializer)
    {
        $validation = new ScriptValidation($checkSignatures, $flags, $txSerializer);

        foreach ($block->getTransactions() as $tx) {
            $blockData->nSigOps += $this->blockCheck->getLegacySigOps($tx);
            if ($blockData->nSigOps > $this->consensus->getParams()->getMaxBlockSigOps()) {
                throw new \RuntimeException('Blocks::accept() - too many sigops');
            }

            if (!$tx->isCoinbase()) {
                if ($flags & InterpreterInterface::VERIFY_P2SH) {
                    $blockData->nSigOps += $this->blockCheck->getP2shSigOps($blockData->utxoView, $tx);
                    if ($blockData->nSigOps > $this->consensus->getParams()->getMaxBlockSigOps()) {
                        throw new \RuntimeException('Blocks::accept() - too many sigops');
                    }
                }

                $blockData->nFees = $this->math->add($blockData->nFees, $blockData->utxoView->getFeePaid($this->math, $tx));
                $this->blockCheck->checkInputs($blockData->utxoView, $tx, $height, $flags, $validation);
            }
        }

        if ($validation->active() && !$validation->result()) {
            throw new \RuntimeException('ScriptValidation failed!');
        }

        $this->blockCheck->checkCoinbaseSubsidy($block->getTransaction(0), $blockData->nFees, $height);
    }

    /**
     * @param BlockInterface $block
     * @param HeaderChainViewInterface $chainView
     * @param Headers $headers
     * @param bool $checkSize
     * @param bool $checkMerkleRoot
     * @return BlockIndexInterface
     */
    public function accept(BlockInterface $block, HeaderChainViewInterface $chainView, Headers $headers, $checkSize = true, $checkMerkleRoot = true)
    {

        $start = microtime(true);
        $init = ['start' => microtime(true), 'end' => null];
        $hash = $block->getHeader()->getHash();
        $index = $headers->accept($hash, $block->getHeader(), true);
        $init['end'] = microtime(true);

        $txSerializer = new CachingTransactionSerializer(new TransactionInputSerializer(new CachingOutPointSerializer()));
        $blockSerializer = new CachingBlockSerializer($this->math, new BlockHeaderSerializer(), $txSerializer);

        $check = ['start' => microtime(true), 'end' => null];
        $this
            ->blockCheck
            ->check($block, $txSerializer, $blockSerializer, $checkSize, $checkMerkleRoot)
            ->checkContextual($block, $chainView->blocks()->getIndex());
        $check['end'] = microtime(true);

        $serializedBlock = $blockSerializer->serialize($block);
        $acceptData = new BlockAcceptData();
        $acceptData->numTx = count($block->getTransactions());
        $acceptData->size = $serializedBlock->getSize();

        $sql = ['start'=> microtime(true)];
        $this->db->insertBlockRaw($hash, $serializedBlock, $acceptData, BlockStatus::ACCEPTED);
        $sql['end'] = microtime(true);

        $chainD = ['start' => microtime(true), 'end' => null];
        $chainView->blocks()->updateTip($index);
        $chainD['end'] = microtime(true);

        $this->emit('block.accept', [$chainView, $index, $block, $acceptData]);

        $total = microtime(true) - $start;
        echo "height: {$index->getHeight()} Num txs: {$acceptData->numTx}; Size {$acceptData->size}; ";
        $this->debugTime("Init", $init, $total);
        $this->debugTime("Check", $check, $total);
        $this->debugTime("SQL", $sql, $total);
        echo "Full {$total}\n";
        echo PHP_EOL;

        return $index;
    }

    private function debugTime($msg, array $time, $total)
    {
        $t = number_format($time['end']-$time['start'], 4);
        $pt = number_format($t / $total*100, 4);
        echo " {$msg}: {$t} {$pt}%";
    }

    /**
     * @param BlockIndexInterface $index
     * @param BlockInterface $block
     * @param HeaderChainViewInterface $chainView
     * @return BlockIndexInterface
     */
    public function connect(BlockIndexInterface $index, BlockInterface $block, HeaderChainViewInterface $chainView, $checkOnly = false)
    {
        $start = microtime(true);
        $init = ['start' => microtime(true), 'end' => null];
        $outpointSerializer = new CachingOutPointSerializer();
        $txSerializer = new CachingTransactionSerializer(new TransactionInputSerializer($outpointSerializer));
        $blockSerializer = new CachingBlockSerializer($this->math, new BlockHeaderSerializer(), $txSerializer);

        $init['end'] = microtime(true);

        $check = ['start' => microtime(true), 'end' => null];
        $this
            ->blockCheck
            ->check($block, $txSerializer, $blockSerializer, !$checkOnly, !$checkOnly);
        $check['end'] = microtime(true);

        $utxoSet = new UtxoSet($this->db, $outpointSerializer);
        $blockData = $this->prepareBatch($block, $txSerializer, $outpointSerializer, $utxoSet);
        list ($wasNext, $forks) = $this->prepareForks($chainView, $index);

        $data = ['start' => microtime(true), 'end' => null];
        $this->checkBlockData($block, $blockData, !$checkOnly, $forks->getFlags(), $index->getHeight(), $txSerializer);
        $data['end'] = microtime(true);

        $sql = ['start' => microtime(true), 'end' => null];
        $this->db->transaction(function () use ($index, $utxoSet, $blockData) {
            $utxoSet->applyBlock($blockData);
            $this->db->updateBlockStatus($index, BlockStatus::VALIDATED);
        });
        $sql['end'] = microtime(true);

        $chainD = ['start' => microtime(true), 'end' => null];
        $chainView->validBlocks()->updateTip($index);
        $forks->next($index);
        if (!$wasNext) {
            $this->forks = $forks;
        }

        $this->emit('block', [$index, $block, $blockData]);
        $chainD['end'] = microtime(true);

        $total = microtime(true) - $start;
        $this->debugTime("Init", $init, $total);
        $this->debugTime("Check", $check, $total);
        $this->debugTime("Validation", $data, $total);
        $this->debugTime("SQL", $sql, $total);
        $this->debugTime("Chain", $chainD, $total);
        echo "Full ".number_format($total, 4).PHP_EOL;

        return $index;
    }
}
