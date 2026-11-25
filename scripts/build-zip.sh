#!/usr/bin/env bash
# Builds dist/cookiepal-whmcs-<version>.zip, holding modules/servers/cookiepal/** only, to unzip into
# the WHMCS root folder. The version is Module::VERSION, the same one the module sends as its User-Agent.
# The files come from the last commit (git archive), so nothing untracked or uncommitted is shipped.
#
# The zip is reproducible: every entry gets the same permissions and the same time, SOURCE_DATE_EPOCH
# when it is set, else the last commit's time.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
module="modules/servers/cookiepal"

if ! git -C "$root" rev-parse --verify -q HEAD > /dev/null; then
    echo "Run this from a git clone with at least one commit: the zip is built from the last commit." >&2
    exit 1
fi
if [[ -n "$(git -C "$root" status --porcelain --untracked-files=all -- "$module")" ]]; then
    echo "Note: $module has changes that aren't committed; the zip holds the last commit's files only." >&2
fi

version="$(git -C "$root" show "HEAD:$module/lib/Module.php" | sed -n "s/.*public const VERSION = '\([^']*\)';.*/\1/p")"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "Could not read Module::VERSION (got '$version')." >&2
    exit 1
fi

epoch="${SOURCE_DATE_EPOCH:-$(git -C "$root" log -1 --format=%ct)}"
if stamp="$(date -u -d "@$epoch" +%Y%m%d%H%M.%S 2>/dev/null)"; then :; else
    stamp="$(date -u -r "$epoch" +%Y%m%d%H%M.%S)"
fi

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
git -C "$root" archive --format=tar HEAD "$module" | tar -x -C "$stage"
find "$stage" -type d -exec chmod 755 {} +
find "$stage" -type f -exec chmod 644 {} +
find "$stage" -exec env TZ=UTC touch -t "$stamp" {} +

out="$root/dist/cookiepal-whmcs-$version.zip"
mkdir -p "$root/dist"
rm -f "$out"
(cd "$stage" && find "$module" -type f | LC_ALL=C sort | TZ=UTC zip -q -X "$out" -@)

echo "$out"
unzip -Z1 "$out"
