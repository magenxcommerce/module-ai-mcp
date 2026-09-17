<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Api;

/**
 * The shape a tool promises its structured result will have.
 *
 * A third interface rather than a method on either of the other two, for the
 * reason {@see ToolAnnotationsInterface} already sets out: both are `@api` and
 * tools contributed by other modules implement them directly, so adding to
 * either breaks every one of those on upgrade. A tool that does not implement
 * this one is advertised exactly as it was.
 *
 * **Declaring a schema is a promise, not a hint.** Unlike the annotations, MCP
 * obliges the `structuredContent` of every successful call to validate against
 * whatever is advertised here, so a schema that is merely approximately right
 * is worse than none at all — it turns a response a client would have accepted
 * into one it rejects.
 *
 * That is why write tools in this module advertise nothing. Every write has two
 * successful shapes, not one: the confirm gate in
 * {@see \Magenx\AiMcp\Model\Protocol\Server} answers an unconfirmed call with a
 * preview — `{preview, tool, arguments, message}` — through the same result
 * helper as an applied call. No single schema describes both, and the preview
 * is the *normal* response rather than the exception. Failures are unaffected:
 * a tool error carries `isError` and no structured content, which the spec does
 * not validate.
 *
 * @api
 */
interface StructuredToolInterface
{
    /**
     * JSON Schema (draft 2020-12 subset) describing the object this tool
     * returns from `execute()`.
     *
     * Returning an empty array advertises no `outputSchema` at all, which is
     * what {@see \Magenx\AiMcp\Model\Tool\AbstractTool} does by default: a tool
     * opts in by overriding, and one that has not thought about it promises
     * nothing.
     *
     * @return array<string, mixed>
     */
    public function getOutputSchema(): array;
}
