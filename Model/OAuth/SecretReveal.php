<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Backend\Model\Session;

/**
 * Carries a just-issued secret from the action that made it to the next render of the connections page, once.
 *
 * The secret used to travel inside a flash message, which buried it in a
 * sentence an admin had to select by hand. It now waits in the admin session
 * — where the flash message kept it too — and the page takes it out on the
 * first render, so a reload no longer shows it.
 */
class SecretReveal
{
    private const SESSION_KEY = 'magenx_aimcp_secret_reveal';

    /**
     * @param Session $session
     */
    public function __construct(
        private readonly Session $session
    ) {
    }

    /**
     * @param string $title What was issued, the panel heading.
     * @param array<int, array{label: string, value: string}> $values What the admin copies, in order.
     * @param string $note What to do with them.
     * @return void
     */
    public function stash(string $title, array $values, string $note): void
    {
        $this->session->setData(self::SESSION_KEY, ['title' => $title, 'values' => $values, 'note' => $note]);
    }

    /**
     * The stashed secret, removed from the session; null when there is none.
     *
     * @return array{title: string, values: array<int, array{label: string, value: string}>, note: string}|null
     */
    public function take(): ?array
    {
        $reveal = $this->session->getData(self::SESSION_KEY, true);

        return is_array($reveal) ? $reveal : null;
    }
}
