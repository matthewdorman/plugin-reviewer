#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
archive="${1:-${repo_dir}/dist/plugin-reviewer.zip}"

command -v unzip >/dev/null 2>&1 || {
	printf '%s\n' 'Error: unzip is required to validate the release.' >&2
	exit 1
}

[[ -f "${archive}" ]] || {
	printf 'Error: release archive not found: %s\n' "${archive}" >&2
	exit 1
}

contents="$(unzip -Z1 "${archive}")"

required=(
	'plugin-reviewer/plugin-reviewer.php'
	'plugin-reviewer/uninstall.php'
	'plugin-reviewer/readme.txt'
	'plugin-reviewer/LICENSE'
)

for path in "${required[@]}"; do
	grep -Fxq "${path}" <<< "${contents}" || {
		printf 'Error: required runtime file is missing: %s\n' "${path}" >&2
		exit 1
	}
done

if grep -Ev '^plugin-reviewer/' <<< "${contents}" | grep -q .; then
	printf '%s\n' 'Error: archive contains files outside plugin-reviewer/.' >&2
	exit 1
fi

if grep -Eiq '(^|/)(\.git|\.github|data|dashboard|dist|scripts|tests?|tools)(/|$)|\.py([co])?$|(^|/)README\.md$' <<< "${contents}"; then
	printf '%s\n' 'Error: archive contains development-only files.' >&2
	exit 1
fi

tmp_dir="$(mktemp -d)"
trap 'rm -rf "${tmp_dir}"' EXIT
unzip -q "${archive}" -d "${tmp_dir}"

find "${tmp_dir}/plugin-reviewer" -type f -name '*.php' -print0 | while IFS= read -r -d '' file; do
	php -l "${file}" >/dev/null
done

plugin_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${tmp_dir}/plugin-reviewer/plugin-reviewer.php" | head -1)"
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${tmp_dir}/plugin-reviewer/readme.txt" | head -1)"
if [[ -z "${plugin_version}" || "${plugin_version}" != "${stable_tag}" ]]; then
	printf 'Error: plugin version (%s) does not match readme stable tag (%s).\n' "${plugin_version}" "${stable_tag}" >&2
	exit 1
fi

printf 'Validated %s\n' "${archive}"
