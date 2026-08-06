#!/usr/bin/env bash
#
# Run the standalone unit tests. No WordPress installation required.
#
#   ./bin/run-tests.sh
#
set -uo pipefail

cd "$(dirname "$0")/.."

status=0

echo "== PHP syntax =="
while IFS= read -r file; do
	if ! out=$(php -l "$file" 2>&1); then
		echo "  FAIL $file"
		echo "$out"
		status=1
	fi
done < <(find . -name '*.php' -not -path './.git/*' -not -path './vendor/*')
[ "$status" -eq 0 ] && echo "  all files parse"

if command -v node >/dev/null 2>&1; then
	echo
	echo "== JS syntax =="
	for file in assets/js/tap-checkout.js assets/js/blocks/tap-blocks.js; do
		if node --check "$file" >/dev/null 2>&1; then
			echo "  ok   $file"
		else
			echo "  FAIL $file"
			node --check "$file"
			status=1
		fi
	done
fi

if command -v node >/dev/null 2>&1; then
	echo
	echo "== JS unit tests =="
	for test in tests/test-*.js; do
		[ -e "$test" ] || continue
		if ! node "$test"; then
			status=1
		fi
	done
fi

echo
echo "== PHP unit tests =="
for test in tests/test-*.php; do
	echo
	echo "-- $test"
	if ! php -d error_reporting=E_ALL -d display_errors=1 "$test"; then
		status=1
	fi
done

echo
if [ "$status" -eq 0 ]; then
	echo "ALL CHECKS PASSED"
else
	echo "CHECKS FAILED"
fi

exit "$status"
