<?php

namespace ErnestDefoe\Millwright\Run;

/**
 * Waiting, and only a web request can end the wait.
 *
 * A step that must run post-swap code in-process cannot run in a long-lived
 * process (see Work\FreshCode). A queue worker that keeps re-dispatching itself
 * to ask again would spin until somebody opened the admin page, so a worker
 * that hears this stops and leaves the run to the page.
 */
class NeedsWebRequest extends NotYet
{
}
