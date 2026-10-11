<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Api;

/**
 * Marks a read tool whose result carries personal data.
 *
 * It declares nothing, and changes nothing about how the tool is listed or
 * called. Its only reader is the read audit in
 * {@see \Magenx\AiMcp\Model\Protocol\Server}: with Audit Reads set to
 * "Personal data only", a successful call to a tool carrying this marker is
 * recorded and every other read is not.
 *
 * A marker rather than a rule over ACL resources or domains, because neither
 * draws the line in the right place: `Magento_Customer::manage` covers
 * `list_customer_groups`, which names no one, and the help desk domain holds
 * lookup lists beside the tickets. The tool knows what it returns; the server
 * does not.
 *
 * Write tools need not carry it. Every attempted write is audited already.
 *
 * @api
 */
interface PersonalDataToolInterface
{
}
