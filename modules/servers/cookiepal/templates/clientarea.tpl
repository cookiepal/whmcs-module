{* CookiePal: the module output on the product details overview (Twenty-One theme, Bootstrap 4). *}
<div class="card mb-4" id="cookiepal-service">
    <div class="card-body">
        <h3 class="card-title">{$cookiepalLang.title|escape}</h3>

        {if $cookiepal.state == 'ready'}
            {if $cookiepal.suspended}
                <div class="alert alert-warning" role="alert">{$cookiepalLang.suspended|escape}</div>
            {/if}
            {if $cookiepal.scheduledDeletion}
                <div class="alert alert-warning" role="alert">{$cookiepalLang.scheduled_deletion|escape}</div>
            {/if}

            <dl class="row mb-3">
                <dt class="col-sm-4">{$cookiepalLang.website|escape}</dt>
                <dd class="col-sm-8">{$cookiepal.domain|escape}</dd>
                <dt class="col-sm-4">{$cookiepalLang.plan|escape}</dt>
                <dd class="col-sm-8">{$cookiepal.planLabel|escape}</dd>
                <dt class="col-sm-4">{$cookiepalLang.installed|escape}</dt>
                <dd class="col-sm-8">
                    {if $cookiepal.installed}
                        <span class="badge badge-success">{$cookiepalLang.yes|escape}</span>
                    {else}
                        <span class="badge badge-secondary">{$cookiepalLang.no|escape}</span>
                        <small class="text-muted d-block">{$cookiepalLang.not_installed_help|escape}</small>
                    {/if}
                </dd>
            </dl>

            {if $cookiepal.snippet}
                <h4>{$cookiepalLang.snippet_heading|escape}</h4>
                <p class="text-muted">{$cookiepalLang.snippet_help|escape}</p>
                <div class="form-group">
                    <textarea id="cookiepal-snippet" class="form-control text-monospace small" rows="3"
                        readonly>{$cookiepal.snippet|escape}</textarea>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm mb-3" id="cookiepal-copy"
                    data-copied="{$cookiepalLang.copied|escape}"
                    data-failed="{$cookiepalLang.copy_failed|escape}">{$cookiepalLang.copy|escape}</button>
            {/if}

            <p class="mb-2">{$cookiepalLang.sso_help|escape}</p>
            <a class="btn btn-primary" target="_blank" rel="noopener"
                href="{$WEB_ROOT}/clientarea.php?action=productdetails&amp;id={$serviceid|escape:'url'}&amp;dosinglesignon=1">{$cookiepalLang.sso_button|escape}</a>
        {elseif $cookiepal.state == 'terminated'}
            <p class="mb-0">{$cookiepalLang.terminated|escape}</p>
        {elseif $cookiepal.state == 'missing'}
            <div class="alert alert-warning mb-0" role="alert">{$cookiepalLang.missing|escape}</div>
        {elseif $cookiepal.state == 'unavailable'}
            <div class="alert alert-warning mb-0" role="alert">{$cookiepalLang.unavailable|escape}</div>
        {elseif $cookiepal.state == 'error'}
            <div class="alert alert-warning mb-0" role="alert">{$cookiepalLang.error|escape}</div>
        {else}
            <p class="mb-0">{$cookiepalLang.pending|escape}</p>
        {/if}
    </div>
</div>

<script>
{literal}
(function () {
    var button = document.getElementById('cookiepal-copy');
    var snippet = document.getElementById('cookiepal-snippet');
    if (!button || !snippet) {
        return;
    }
    var label = button.textContent;
    var timer = null;
    var show = function (text) {
        button.textContent = text;
        clearTimeout(timer);
        timer = setTimeout(function () {
            button.textContent = label;
        }, 2000);
    };
    var copyBySelection = function () {
        snippet.select();
        var copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (e) {
            copied = false;
        }
        show(button.getAttribute(copied ? 'data-copied' : 'data-failed'));
    };
    button.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(snippet.value).then(function () {
                show(button.getAttribute('data-copied'));
            }, copyBySelection);
        } else {
            copyBySelection();
        }
    });
})();
{/literal}
</script>
