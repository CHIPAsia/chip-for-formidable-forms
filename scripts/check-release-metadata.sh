#!/usr/bin/env bash
#
# Release-metadata consistency checks.
#
# A plugin's declared compatibility lives in several files that nothing makes
# agree. WordPress reads the plugin header's `Requires at least` / `Requires
# PHP` to decide whether a site may install or update at all. The readme.txt
# copy is what a merchant sees on the plugin page. README.md is what a GitHub
# visitor reads. phpcs.xml decides which APIs the sniffs may demand.
#
# Drift is invisible: each file is plausible read alone, and the value that
# reaches a user is usually not the one the installer checks. This script is
# the gate that keeps them equal. It runs in CI so a mismatch fails the build
# rather than reaching WordPress.org.
#
# Usage: scripts/check-release-metadata.sh
# Exit: 0 when every check holds, 1 on the first group that does not.

set -uo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

fail=0
pass=0

ok() {
	printf 'ok    %s\n' "$1"
	pass=$((pass + 1))
}

bad() {
	printf 'FAIL  %s\n' "$1"
	if [ -n "${2:-}" ]; then
		printf '      %s\n' "$2"
	fi
	fail=$((fail + 1))
}

# Read the first match of a pattern from a file.
grab() {
	sed -nE "s/$2/\1/p" "$1" | head -n1 | tr -d ' \r'
}

header_file="chip-for-formidable-forms.php"
readme_file="readme.txt"

if [ ! -f "$header_file" ] || [ ! -f "$readme_file" ]; then
	bad "expected $header_file and $readme_file at the repository root"
	exit 1
fi

# --- the floors ------------------------------------------------------------

h_wp="$(grab "$header_file" '^[[:space:]]*\*[[:space:]]*Requires at least:[[:space:]]*([0-9.]+).*')"
h_php="$(grab "$header_file" '^[[:space:]]*\*[[:space:]]*Requires PHP:[[:space:]]*([0-9.]+).*')"
r_wp="$(grab "$readme_file" '^Requires at least:[[:space:]]*([0-9.]+).*')"
r_php="$(grab "$readme_file" '^Requires PHP:[[:space:]]*([0-9.]+).*')"
tested="$(grab "$readme_file" '^Tested up to:[[:space:]]*([0-9.]+).*')"

if [ -n "$h_wp" ]; then
	ok "the plugin header declares 'Requires at least' ($h_wp)"
else
	bad "the plugin header declares 'Requires at least'" \
		"WordPress reads this header to decide whether a site may install"
fi

if [ -n "$h_php" ]; then
	ok "the plugin header declares 'Requires PHP' ($h_php)"
else
	bad "the plugin header declares 'Requires PHP'" \
		"WordPress reads this header to decide whether a site may install"
fi

if [ -n "$r_wp" ] && [ "$h_wp" = "$r_wp" ]; then
	ok "the WordPress floor agrees between the header and readme.txt ($r_wp)"
else
	bad "the WordPress floor agrees between the header and readme.txt" \
		"header='$h_wp' readme='$r_wp'"
fi

if [ -n "$r_php" ] && [ "$h_php" = "$r_php" ]; then
	ok "the PHP floor agrees between the header and readme.txt ($r_php)"
else
	bad "the PHP floor agrees between the header and readme.txt" \
		"header='$h_php' readme='$r_php'"
fi

if printf '%s' "$tested" | grep -qE '^[0-9]+\.[0-9]+$'; then
	ok "'Tested up to' is a MAJOR.MINOR version ($tested)"
else
	bad "'Tested up to' is a MAJOR.MINOR version" "got '$tested'"
fi

# --- the version triplet ---------------------------------------------------

h_ver="$(grab "$header_file" '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([0-9.]+).*')"
constant="$(grep -oE "FRM_CHIP_MODULE_VERSION',[[:space:]]*'v?[0-9.]+" "$header_file" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -n1)"
stable="$(grab "$readme_file" '^Stable tag:[[:space:]]*([0-9.]+).*')"

if [ -n "$h_ver" ] && [ "$h_ver" = "$constant" ]; then
	ok "the header Version matches FRM_CHIP_MODULE_VERSION ($h_ver)"
else
	bad "the header Version matches FRM_CHIP_MODULE_VERSION" \
		"header='$h_ver' constant='$constant'"
fi

if [ -n "$h_ver" ] && [ "$h_ver" = "$stable" ]; then
	ok "Stable tag matches the header Version ($stable)"
else
	bad "Stable tag matches the header Version" \
		"header='$h_ver' stable='$stable'"
fi

# --- the copies a reader sees ---------------------------------------------

if grep -q "WordPress $r_wp" README.md; then
	ok "README.md states the same WordPress floor"
else
	bad "README.md states the same WordPress floor" "looking for 'WordPress $r_wp'"
fi

if grep -q "PHP $r_php" README.md; then
	ok "README.md states the same PHP floor"
else
	bad "README.md states the same PHP floor" "looking for 'PHP $r_php'"
fi

phpcs_range="$(grab "phpcs.xml" '.*testVersion"?[[:space:]]+value="([^"]+)".*')"
if [ -n "$phpcs_range" ] && [ "${phpcs_range#"$r_php"}" != "$phpcs_range" ]; then
	ok "phpcs.xml targets the same PHP floor ($phpcs_range)"
else
	bad "phpcs.xml targets the same PHP floor" \
		"testVersion='$phpcs_range' declared='$r_php'"
fi

# --- readme.txt carries the shipping release only --------------------------
# WordPress.org renders the changelog from readme.txt, so a second entry
# publishes stale notes on the plugin page.

entries="$(grep -cE '^= [0-9]+(\.[0-9]+)+' "$readme_file")"
if [ "$entries" = "1" ]; then
	ok "readme.txt carries exactly one changelog entry"
else
	bad "readme.txt carries exactly one changelog entry" "found $entries"
fi

entry_ver="$(grab "$readme_file" '^= ([0-9]+(\.[0-9]+)+).*')"
if [ -n "$entry_ver" ] && [ "$entry_ver" = "$h_ver" ]; then
	ok "the readme changelog entry is the version being shipped ($entry_ver)"
else
	bad "the readme changelog entry is the version being shipped" \
		"entry='$entry_ver' shipping='$h_ver'"
fi

# --- every declared screenshot has a file ---------------------------------
# A declared screenshot with no image makes WordPress.org serve a 404.

declared="$(sed -nE '/^== Screenshots ==/,/^== [^S]/p' "$readme_file" \
	| grep -oE '^[0-9]+\.' | tr -d '.' | sort -n)"

if [ -z "$declared" ]; then
	bad "readme.txt declares at least one screenshot"
else
	missing=""
	for n in $declared; do
		[ -f ".wordpress-org/screenshot-$n.png" ] || missing="$missing screenshot-$n.png"
	done

	if [ -z "$missing" ]; then
		ok "every screenshot readme.txt names has a file"
	else
		bad "every screenshot readme.txt names has a file" "missing:$missing"
	fi

	highest="$(printf '%s\n' "$declared" | tail -n1)"
	expected="$(seq 1 "$highest")"
	if [ "$(printf '%s' "$declared" | tr '\n' ' ' | tr -s ' ')" = "$(printf '%s' "$expected" | tr '\n' ' ' | tr -s ' ')" ]; then
		ok "declared screenshots are numbered contiguously from 1"
	else
		bad "declared screenshots are numbered contiguously from 1" \
			"declared: $(printf '%s' "$declared" | tr '\n' ' ')"
	fi
fi

# ---------------------------------------------------------------------------

printf '\n%s\n' "---------------------------------------------------"
printf '  release metadata: %s ok, %s failed\n' "$pass" "$fail"

[ "$fail" -eq 0 ]
