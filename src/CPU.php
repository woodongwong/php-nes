<?php

namespace phpnes;

/**
 * Class CPU
 * @package phpnes
 */
class CPU
{
    public $nes = null;

    public $mem = null;                 //内存
    public $REG_ACC = null;
    public $REG_X = null;
    public $REG_Y = null;
    public $REG_SP = null;
    public $REG_PC = null;
    public $REG_PC_NEW = null;
    public $REG_STATUS = null;
    public $F_CARRY = null;
    public $F_DECIMAL = null;
    public $F_INTERRUPT = null;
    public $F_INTERRUPT_NEW = null;
    public $F_OVERFLOW = null;
    public $F_SIGN = null;
    public $F_ZERO = null;
    public $F_NOTUSED = null;
    public $F_NOTUSED_NEW = null;
    public $F_BRK = null;
    public $F_BRK_NEW = null;
    public $opdata = null;
    public $cyclesToHalt = null;
    public $crash = null;
    public $irqRequested = null;
    public $irqType = null;

    //中断请求（IRQ）类型
    const IRQ_NORMAL = 0;   //正常
    const IRQ_NMI = 1;      //不可屏蔽中断
    const IRQ_RESET = 2;    //重置

    /**
     * CPU constructor.
     *
     * @param $nes
     */
    public function __construct($nes)
    {
        $this->nes = $nes;
        $this->reset();
    }

    /**
     * 重置
     */
    public function reset()
    {
        //重置内存
        //$this->mem = array_pad([], 0x10000, null);   //65536

        //0-8192填充255  8KB RAM随机存储器
        $this->mem = array_fill(0, 0x2000, 0xff);
        for ($i = 0; $i < 4; $i++) {
            $p = $i * 0x800;
            //$this->mem[$p + 0x008] =
        }

        //IO registers(io寄存器)
    }

    /**
     * 模拟单个CPU指令，返回循环次数
     */
    public function emulate()
    {
        //检查中断
        if ($this->irqRequested) {

            $temp = $this->F_CARRY |
                (($this->F_ZERO === 0 ? 1 : 0) << 1) |
                ($this->F_INTERRUPT << 2) |
                ($this->F_DECIMAL << 3) |
                ($this->F_BRK << 4) |
                ($this->F_NOTUSED << 5) |
                ($this->F_OVERFLOW << 6) |
                ($this->F_SIGN << 7);

            $this->REG_PC_NEW = $this->REG_PC;
            $this->F_INTERRUPT_NEW = $this->F_INTERRUPT;

            switch ($this->irqType) {
                case self::IRQ_NORMAL:
                    //Normal
                    if ($this->F_INTERRUPT !== 0) {
                        break;
                    }
                    $this->doIrq($temp);
                    break;

                case self::IRQ_NMI:
                    //NMI
                    $this->doNonMaskableInterrupt($temp);
                    break;

                case self::IRQ_RESET:
                    //Reset
                    $this->doResetInterrupt();
                    break;

            }

            $this->REG_PC = $this->REG_PC_NEW;
            $this->F_INTERRUPT = $this->F_INTERRUPT_NEW;
            $this->F_BRK = $this->F_BRK_NEW;
            $this->irqRequested = false;
        }

        $opinf = $this->opdata[$this->nes->mmap->load($this->REG_PC + 1)];
        $cycleCount = $opinf >> 24;
        $cycleAdd = 0;

        //查找地址模式
        $addrMode = ($opinf >> 8) & 0xff;

        //按操作字节数增加PC
        $opaddr = $this->REG_PC;
        $this->REG_PC += ($opinf >> 16) & 0xff;

        $addr = 0;

        switch ($addrMode) {
            case 0:
                //零页模式。 使用操作码后给出的地址，但没有高字节。
                $addr = $this->load($opaddr + 2);
                break;

            case 1:
                //相对模式
                $addr = $this->load($opaddr + 2);
                if ($addr < 0x80) {
                    $addr += $this->REG_PC;
                } else {
                    $addr += $this->REG_PC - 256;
                }
                break;
            case 2:
                //忽视，地址隐含在指令中。
                break;
            case 3:
                //绝对模式 使用操作码后面的两个字节作为一个地址。
                $addr = $this->load16bit($opaddr + 2);
                break;
            case 4:
                //累加器模式。 地址在累加器寄存器中
                $addr = $this->REG_ACC;
                break;
            case 5:
                //即时模式。该值在操作码后给出。
                $addr = $this->REG_PC;
                break;
            case 6:
                //零页索引模式，X作为索引。 使用操作码后给出的地址，然后将X寄存器添加到其中以获取最终地址。
                $addr = ($this->load($opaddr + 2) + $this->REG_X) & 0xff;
                break;
            case 7:
                //零页面索引模式，Y作为索引。 使用操作码后给出的地址，然后将Y寄存器添加到其中以获取最终地址。
                $addr = ($this->load($opaddr + 2) + $this->REG_Y) & 0xff;
                break;
            case 8:
                //绝对索引模式，X作为索引。 与零页索引相同，但具有高字节。
                $addr = $this->load16bit($opaddr + 2);
                if (($addr & 0xff00) !== (($addr + $this->REG_X) & 0xff00)) {
                    $cycleAdd = 1;
                }
                $addr += $this->REG_X;
                break;
            case 9:
                //绝对索引模式，Y作为索引。 与零页索引相同，但具有高字节。
                $addr = $this->load16bit($opaddr + 2);
                if (($addr & 0xff00) !== (($addr + $this->REG_Y) & 0xff00)) {
                    $cycleAdd = 1;
                }
                $addr += $this->REG_Y;
                break;
            case 10:
                //预索引间接模式。 找到从给定位置开始的16位地址加上当前的X寄存器。 该值是该地址的内容。
                $addr = $this->load($opaddr + 2);
                if (($addr & 0xff00) !== (($addr + $this->REG_X) & 0xff00)) {
                    $cycleAdd = 1;
                }
                $addr += $this->REG_X;
                $addr &= 0xff;
                $addr = $this->load16bit($addr);
                break;
            case 11:
                //后索引间接模式。 找到给定位置（以及后面的位置）中包含的16位地址。 将该寄存器的内容添加到该地址。 获取存储在该地址的值。
                $addr = $this->load16bit($this->load($opaddr + 2));
                if (($addr & 0xff00) !== (($addr + $this->REG_Y) & 0xff00)) {
                    $cycleAdd = 1;
                }
                $addr += $this->REG_Y;
                break;
            case 12:
                //间接绝对模式。 找到给定位置包含的16位地址。
                $addr = $this->load16bit($opaddr + 2); // Find op
                if ($addr < 0x1fff) {
                    // Read from address given in op
                    $addr = $this->mem[$addr] + ($this->mem[($addr & 0xff00) | ((($addr & 0xff) + 1) & 0xff)] << 8);
                } else {
                    $addr = $this->nes->mmap->load($addr) +
                        ($this->nes->mmap->load(($addr & 0xff00) | ((($addr & 0xff) + 1) & 0xff)) << 8);
                }
                break;
        }

        //绕过0xFFFF以上的地址
        $addr &= 0xffff;

        // ----------------------------------------------------------------------------------------------------
        // 解码并执行指令
        // ----------------------------------------------------------------------------------------------------

        //这应该是编译为一个跳转表。
        switch ($opinf & 0xff) {
            case 0:
                // *******
                // * ADC *
                // *******

                // Add with carry.
                $temp = $this->REG_ACC + $this->load($addr) + $this->F_CARRY;

                if (
                    (($this->REG_ACC ^ $this->load($addr)) & 0x80) === 0 &&
                    (($this->REG_ACC ^ $temp) & 0x80) !== 0
                ) {
                    $this->F_OVERFLOW = 1;
                } else {
                    $this->F_OVERFLOW = 0;
                }
                $this->F_CARRY = $temp > 255 ? 1 : 0;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp & 0xff;
                $this->REG_ACC = $temp & 255;
                $cycleCount += $cycleAdd;
                break;
            case 1:
                // *******
                // * AND *
                // *******

                // AND memory with accumulator.
                $this->REG_ACC = $this->REG_ACC & $this->load($addr);
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                //$this->REG_ACC =$temp;
                if ($addrMode !== 11) $cycleCount += $cycleAdd; // PostIdxInd = 11
                break;
            case 2:
                // *******
                // * ASL *
                // *******

                // Shift left one bit
                if ($addrMode === 4) {
                    // ADDR_ACC = 4

                    $this->F_CARRY = ($this->REG_ACC >> 7) & 1;
                    $this->REG_ACC = ($this->REG_ACC << 1) & 255;
                    $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                    $this->F_ZERO = $this->REG_ACC;
                } else {
                    $temp = $this->load($addr);
                    $this->F_CARRY = ($temp >> 7) & 1;
                    $temp = ($temp << 1) & 255;
                    $this->F_SIGN = ($temp >> 7) & 1;
                    $this->F_ZERO = $temp;
                    $this->write($addr, $temp);
                }
                break;
            case 3:
                // *******
                // * BCC *
                // *******

                // Branch on carry clear
                if ($this->F_CARRY === 0) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 4:
                // *******
                // * BCS *
                // *******

                // Branch on carry set
                if ($this->F_CARRY === 1) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 5:
                // *******
                // * BEQ *
                // *******

                // Branch on zero
                if ($this->F_ZERO === 0) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 6:
                // *******
                // * BIT *
                // *******

                $temp = $this->load($addr);
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_OVERFLOW = ($temp >> 6) & 1;
                $temp &= $this->REG_ACC;
                $this->F_ZERO = $temp;
                break;
            case 7:
                // *******
                // * BMI *
                // *******

                // Branch on negative result
                if ($this->F_SIGN === 1) {
                    $cycleCount++;
                    $this->REG_PC = $addr;
                }
                break;
            case 8:
                // *******
                // * BNE *
                // *******

                // Branch on not zero
                if ($this->F_ZERO !== 0) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 9:
                // *******
                // * BPL *
                // *******

                // Branch on positive result
                if ($this->F_SIGN === 0) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 10:
                // *******
                // * BRK *
                // *******

                $this->REG_PC += 2;
                $this->push(($this->REG_PC >> 8) & 255);
                $this->push($this->REG_PC & 255);
                $this->F_BRK = 1;

                $this->push(
                    $this->F_CARRY |
                    (($this->F_ZERO === 0 ? 1 : 0) << 1) |
                    ($this->F_INTERRUPT << 2) |
                    ($this->F_DECIMAL << 3) |
                    ($this->F_BRK << 4) |
                    ($this->F_NOTUSED << 5) |
                    ($this->F_OVERFLOW << 6) |
                    ($this->F_SIGN << 7)
                );

                $this->F_INTERRUPT = 1;
                //$this->REG_PC = load(0xFFFE) | ($load(0xFFFF) << 8);
                $this->REG_PC = $this->load16bit(0xfffe);
                $this->REG_PC--;
                break;
            case 11:
                // *******
                // * BVC *
                // *******

                // Branch on overflow clear
                if ($this->F_OVERFLOW === 0) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 12:
                // *******
                // * BVS *
                // *******

                // Branch on overflow set
                if ($this->F_OVERFLOW === 1) {
                    $cycleCount += ($opaddr & 0xff00) !== ($addr & 0xff00) ? 2 : 1;
                    $this->REG_PC = $addr;
                }
                break;
            case 13:
                // *******
                // * CLC *
                // *******

                // Clear carry flag
                $this->F_CARRY = 0;
                break;
            case 14:
                // *******
                // * CLD *
                // *******

                // Clear decimal flag
                $this->F_DECIMAL = 0;
                break;
            case 15:
                // *******
                // * CLI *
                // *******

                // Clear interrupt flag
                $this->F_INTERRUPT = 0;
                break;
            case 16:
                // *******
                // * CLV *
                // *******

                // Clear overflow flag
                $this->F_OVERFLOW = 0;
                break;
            case 17:
                // *******
                // * CMP *
                // *******

                // Compare memory and accumulator:
                $temp = $this->REG_ACC - $this->load($addr);
                $this->F_CARRY = $temp >= 0 ? 1 : 0;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp & 0xff;
                $cycleCount += $cycleAdd;
                break;
            case 18:
                // *******
                // * CPX *
                // *******

                // Compare memory and index X:
                $temp = $this->REG_X - $this->load($addr);
                $this->F_CARRY = $temp >= 0 ? 1 : 0;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp & 0xff;
                break;
            case 19:
                // *******
                // * CPY *
                // *******

                // Compare memory and index Y:
                $temp = $this->REG_Y - $this->load($addr);
                $this->F_CARRY = $temp >= 0 ? 1 : 0;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp & 0xff;
                break;
            case 20:
                // *******
                // * DEC *
                // *******

                // Decrement memory by one:
                $temp = ($this->load($addr) - 1) & 0xff;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp;
                $this->write($addr, $temp);
                break;
            case 21:
                // *******
                // * DEX *
                // *******

                // Decrement index X by one:
                $this->REG_X = ($this->REG_X - 1) & 0xff;
                $this->F_SIGN = ($this->REG_X >> 7) & 1;
                $this->F_ZERO = $this->REG_X;
                break;
            case 22:
                // *******
                // * DEY *
                // *******

                // Decrement index Y by one:
                $this->REG_Y = ($this->REG_Y - 1) & 0xff;
                $this->F_SIGN = ($this->REG_Y >> 7) & 1;
                $this->F_ZERO = $this->REG_Y;
                break;
            case 23:
                // *******
                // * EOR *
                // *******

                // XOR Memory with accumulator, store in accumulator:
                $this->REG_ACC = ($this->load($addr) ^ $this->REG_ACC) & 0xff;
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                $cycleCount += $cycleAdd;
                break;
            case 24:
                // *******
                // * INC *
                // *******

                // Increment memory by one:
                $temp = ($this->load($addr) + 1) & 0xff;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp;
                $this->write($addr, $temp & 0xff);
                break;
            case 25:
                // *******
                // * INX *
                // *******

                // Increment index X by one:
                $this->REG_X = ($this->REG_X + 1) & 0xff;
                $this->F_SIGN = ($this->REG_X >> 7) & 1;
                $this->F_ZERO = $this->REG_X;
                break;
            case 26:
                // *******
                // * INY *
                // *******

                // Increment index Y by one:
                $this->REG_Y++;
                $this->REG_Y &= 0xff;
                $this->F_SIGN = ($this->REG_Y >> 7) & 1;
                $this->F_ZERO = $this->REG_Y;
                break;
            case 27:
                // *******
                // * JMP *
                // *******

                // Jump to new location:
                $this->REG_PC = $addr - 1;
                break;
            case 28:
                // *******
                // * JSR *
                // *******

                // Jump to new location, saving return$address.
                // Push return$address on stack:
                $this->push(($this->REG_PC >> 8) & 255);
                $this->push($this->REG_PC & 255);
                $this->REG_PC = $addr - 1;
                break;
            case 29:
                // *******
                // * LDA *
                // *******

                // Load accumulator with memory:
                $this->REG_ACC = $this->load($addr);
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                $cycleCount += $cycleAdd;
                break;
            case 30:
                // *******
                // * LDX *
                // *******

                // Load index X with memory:
                $this->REG_X = $this->load($addr);
                $this->F_SIGN = ($this->REG_X >> 7) & 1;
                $this->F_ZERO = $this->REG_X;
                $cycleCount += $cycleAdd;
                break;
            case 31:
                // *******
                // * LDY *
                // *******

                // Load index Y with memory:
                $this->REG_Y = $this->load($addr);
                $this->F_SIGN = ($this->REG_Y >> 7) & 1;
                $this->F_ZERO = $this->REG_Y;
                $cycleCount += $cycleAdd;
                break;
            case 32:
                // *******
                // * LSR *
                // *******

                // Shift right one bit:
                if ($addrMode === 4) {
                    //$addr_ACC

                    $temp = $this->REG_ACC & 0xff;
                    $this->F_CARRY = $temp & 1;
                    $temp >>= 1;
                    $this->REG_ACC = $temp;
                } else {
                    $temp = $this->load($addr) & 0xff;
                    $this->F_CARRY = $temp & 1;
                    $temp >>= 1;
                    $this->write($addr, $temp);
                }
                $this->F_SIGN = 0;
                $this->F_ZERO = $temp;
                break;
            case 33:
                // *******
                // * NOP *
                // *******

                // No OPeration.
                // Ignore.
                break;
            case 34:
                // *******
                // * ORA *
                // *******

                // OR memory with accumulator, store in accumulator.
                $temp = ($this->load($addr) | $this->REG_ACC) & 255;
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp;
                $this->REG_ACC = $temp;
                if ($addrMode !== 11) {
                    $cycleCount += $cycleAdd;
                } // PostIdxInd = 11
                break;
            case 35:
                // *******
                // * PHA *
                // *******

                // Push accumulator on stack
                $this->push($this->REG_ACC);
                break;
            case 36:
                // *******
                // * PHP *
                // *******

                // Push processor status on stack
                $this->F_BRK = 1;
                $this->push(
                    $this->F_CARRY |
                    (($this->F_ZERO === 0 ? 1 : 0) << 1) |
                    ($this->F_INTERRUPT << 2) |
                    ($this->F_DECIMAL << 3) |
                    ($this->F_BRK << 4) |
                    ($this->F_NOTUSED << 5) |
                    ($this->F_OVERFLOW << 6) |
                    ($this->F_SIGN << 7)
                );
                break;
            case 37:
                // *******
                // * PLA *
                // *******

                // Pull accumulator from stack
                $this->REG_ACC = $this->pull();
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                break;
            case 38:
                // *******
                // * PLP *
                // *******

                // Pull processor status from stack
                $temp = $this->pull();
                $this->F_CARRY = $temp & 1;
                $this->F_ZERO = (($temp >> 1) & 1) === 1 ? 0 : 1;
                $this->F_INTERRUPT = ($temp >> 2) & 1;
                $this->F_DECIMAL = ($temp >> 3) & 1;
                $this->F_BRK = ($temp >> 4) & 1;
                $this->F_NOTUSED = ($temp >> 5) & 1;
                $this->F_OVERFLOW = ($temp >> 6) & 1;
                $this->F_SIGN = ($temp >> 7) & 1;

                $this->F_NOTUSED = 1;
                break;
            case 39:
                // *******
                // * ROL *
                // *******

                // Rotate one bit left
                if ($addrMode === 4) {
                    //$addr_ACC = 4

                    $temp = $this->REG_ACC;
                    $add = $this->F_CARRY;
                    $this->F_CARRY = ($temp >> 7) & 1;
                    $temp = (($temp << 1) & 0xff) + $add;
                    $this->REG_ACC = $temp;
                } else {
                    $temp = $this->load($addr);
                    $add = $this->F_CARRY;
                    $this->F_CARRY = ($temp >> 7) & 1;
                    $temp = (($temp << 1) & 0xff) + $add;
                    $this->write($addr, $temp);
                }
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp;
                break;
            case 40:
                // *******
                // * ROR *
                // *******

                // Rotate one bit right
                if ($addrMode === 4) {
                    //$addr_ACC = 4

                    $add = $this->F_CARRY << 7;
                    $this->F_CARRY = $this->REG_ACC & 1;
                    $temp = ($this->REG_ACC >> 1) + $add;
                    $this->REG_ACC = $temp;
                } else {
                    $temp = $this->load($addr);
                    $add = $this->F_CARRY << 7;
                    $this->F_CARRY = $temp & 1;
                    $temp = ($temp >> 1) + $add;
                    $this->write($addr, $temp);
                }
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp;
                break;
            case 41:
                // *******
                // * RTI *
                // *******

                // Return from interrupt. Pull status and PC from stack.

                $temp = $this->pull();
                $this->F_CARRY = $temp & 1;
                $this->F_ZERO = (($temp >> 1) & 1) === 0 ? 1 : 0;
                $this->F_INTERRUPT = ($temp >> 2) & 1;
                $this->F_DECIMAL = ($temp >> 3) & 1;
                $this->F_BRK = ($temp >> 4) & 1;
                $this->F_NOTUSED = ($temp >> 5) & 1;
                $this->F_OVERFLOW = ($temp >> 6) & 1;
                $this->F_SIGN = ($temp >> 7) & 1;

                $this->REG_PC = $this->pull();
                $this->REG_PC += $this->pull() << 8;
                if ($this->REG_PC === 0xffff) {
                    return;
                }
                $this->REG_PC--;
                $this->F_NOTUSED = 1;
                break;
            case 42:
                // *******
                // * RTS *
                // *******

                // Return from subroutine. Pull PC from stack.

                $this->REG_PC = $this->pull();
                $this->REG_PC += $this->pull() << 8;

                if ($this->REG_PC === 0xffff) {
                    return; // return from NSF play routine:
                }
                break;
            case 43:
                // *******
                // * SBC *
                // *******

                $temp = $this->REG_ACC - $this->load($addr) - (1 - $this->F_CARRY);
                $this->F_SIGN = ($temp >> 7) & 1;
                $this->F_ZERO = $temp & 0xff;
                if (
                    (($this->REG_ACC ^ $temp) & 0x80) !== 0 &&
                    (($this->REG_ACC ^ $this->load($addr)) & 0x80) !== 0
                ) {
                    $this->F_OVERFLOW = 1;
                } else {
                    $this->F_OVERFLOW = 0;
                }
                $this->F_CARRY = $temp < 0 ? 0 : 1;
                $this->REG_ACC = $temp & 0xff;
                if ($addrMode !== 11) $cycleCount += $cycleAdd; // PostIdxInd = 11
                break;
            case 44:
                // *******
                // * SEC *
                // *******

                // Set carry flag
                $this->F_CARRY = 1;
                break;
            case 45:
                // *******
                // * SED *
                // *******

                // Set decimal mode
                $this->F_DECIMAL = 1;
                break;
            case 46:
                // *******
                // * SEI *
                // *******

                // Set interrupt disable status
                $this->F_INTERRUPT = 1;
                break;
            case 47:
                // *******
                // * STA *
                // *******

                // Store accumulator in memory
                $this->write($addr, $this->REG_ACC);
                break;
            case 48:
                // *******
                // * STX *
                // *******

                // Store index X in memory
                $this->write($addr, $this->REG_X);
                break;
            case 49:
                // *******
                // * STY *
                // *******

                // Store index Y in memory:
                $this->write($addr, $this->REG_Y);
                break;
            case 50:
                // *******
                // * TAX *
                // *******

                // Transfer accumulator to index X:
                $this->REG_X = $this->REG_ACC;
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                break;
            case 51:
                // *******
                // * TAY *
                // *******

                // Transfer accumulator to index Y:
                $this->REG_Y = $this->REG_ACC;
                $this->F_SIGN = ($this->REG_ACC >> 7) & 1;
                $this->F_ZERO = $this->REG_ACC;
                break;
            case 52:
                // *******
                // * TSX *
                // *******

                // Transfer stack pointer to index X:
                $this->REG_X = $this->REG_SP - 0x0100;
                $this->F_SIGN = ($this->REG_SP >> 7) & 1;
                $this->F_ZERO = $this->REG_X;
                break;
            case 53:
                // *******
                // * TXA *
                // *******

                // Transfer index X to accumulator:
                $this->REG_ACC = $this->REG_X;
                $this->F_SIGN = ($this->REG_X >> 7) & 1;
                $this->F_ZERO = $this->REG_X;
                break;
            case 54:
                // *******
                // * TXS *
                // *******

                // Transfer index X to stack pointer:
                $this->REG_SP = $this->REG_X + 0x0100;
                $this->stackWrap();
                break;
            case 55:
                // *******
                // * TYA *
                // *******

                // Transfer index Y to accumulator:
                $this->REG_ACC = $this->REG_Y;
                $this->F_SIGN = ($this->REG_Y >> 7) & 1;
                $this->F_ZERO = $this->REG_Y;
                break;
            default:
                // *******
                // * ??? *
                // *******

                $this->nes->stop();
                $this->nes->crashMessage = "Game crashed, invalid opcode at address $" + $opaddr->toString(16);
                break;
        }
        /*----------end of switch--------*/

        return $cycleCount;
    }
}