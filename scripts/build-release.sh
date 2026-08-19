#!/usr/bin/env bash
set -euo pipefail

repo_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
dist_dir="${repo_dir}/dist"
stage_dir="${dist_dir}/plugin-reviewer"
archive="${dist_dir}/plugin-reviewer.zip"

command -v zip >/dev/null 2>&1 || {
	printf '%s\n' 'Error: zip is required to build the release.' >&2
	exit 1
}

rm -rf "${stage_dir}" "${archive}"
mkdir -p "${stage_dir}/assets/css" "${stage_dir}/includes" "${stage_dir}/languages"

cp "${repo_dir}/plugin-reviewer.php" "${stage_dir}/"
cp "${repo_dir}/uninstall.php" "${stage_dir}/"
cp "${repo_dir}/readme.txt" "${stage_dir}/"
cp "${repo_dir}/LICENSE" "${stage_dir}/"
cp "${repo_dir}/assets/css/admin.css" "${stage_dir}/assets/css/"
cp "${repo_dir}"/includes/*.php "${stage_dir}/includes/"
cp "${repo_dir}"/languages/*.pot "${stage_dir}/languages/"

(cd "${dist_dir}" && zip -q -r "${archive}" plugin-reviewer)
printf 'Built %s\n' "${archive}"
