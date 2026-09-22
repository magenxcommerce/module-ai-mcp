<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;

use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;

/**
 * Stands in for one of the object manager's generated `\Proxy` classes.
 *
 * A real proxy builds its subject on the first method call and is transparent
 * afterwards, which makes "was this tool built" and "was a method called on it"
 * the same question. That is the question these tests ask, so this records the
 * answer instead of deferring anything: it is already the tool.
 *
 * The class sits in a namespace named after the class it extends, because that
 * is where the object manager puts a proxy and it is what
 * {@see \Magenx\AiMcp\Model\Tool\ToolCatalog} has to unwrap.
 *
 * Every public method the server reaches for is overridden, so a caller that is
 * supposed to work from the registration alone cannot quietly start asking the
 * tool instead.
 */
class Proxy extends FlatTool
{
    /** Whether anything has asked this tool a question yet. */
    public bool $woken = false;

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        $this->woken = true;

        return parent::getName();
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        $this->woken = true;

        return parent::getDescription();
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        $this->woken = true;

        return parent::getInputSchema();
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        $this->woken = true;

        return parent::getAclResource();
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        $this->woken = true;

        return parent::isWrite();
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): ?string
    {
        $this->woken = true;

        return parent::getTitle();
    }

    /**
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        $this->woken = true;

        return parent::getAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function getOutputSchema(): array
    {
        $this->woken = true;

        return parent::getOutputSchema();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $this->woken = true;

        return parent::execute($arguments);
    }
}
