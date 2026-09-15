#!/usr/bin/env bash
set -euo pipefail

TAG="${1:?Usage: generate-release-notes.sh <tag> <output-file>}"
OUTPUT="${2:?Usage: generate-release-notes.sh <tag> <output-file>}"
REPOSITORY="${GITHUB_REPOSITORY:-}"
REPOSITORY_URL=""

if [[ -n "$REPOSITORY" ]]; then
    REPOSITORY_URL="https://github.com/${REPOSITORY}"
fi

PREVIOUS_TAG="$(git describe --tags --abbrev=0 "${TAG}^" 2>/dev/null || true)"

if [[ -n "$PREVIOUS_TAG" ]]; then
    RANGE="${PREVIOUS_TAG}..${TAG}"
else
    RANGE="${TAG}"
fi

declare -a breaking=()
declare -a features=()
declare -a fixes=()
declare -a security=()
declare -a performance=()
declare -a refactor=()
declare -a docs=()
declare -a ci=()
declare -a other=()

format_commit() {
    local sha="$1"
    local subject="$2"
    local short_sha="${sha:0:7}"

    if [[ -n "$REPOSITORY_URL" ]]; then
        printf '%s ([`%s`](%s/commit/%s))' "$subject" "$short_sha" "$REPOSITORY_URL" "$sha"
    else
        printf '%s (`%s`)' "$subject" "$short_sha"
    fi
}

normalize_subject() {
    local subject="$1"

    subject="$(printf '%s' "$subject" | sed -E 's/^(feat|feature|fix|bugfix|hotfix|security|sec|perf|performance|refactor|docs|doc|ci|build|chore|deps|dependencies)(\([^)]*\))?!?:[[:space:]]*//I')"

    if [[ -n "$subject" ]]; then
        subject="$(printf '%s' "${subject:0:1}" | tr '[:lower:]' '[:upper:]')${subject:1}"
    fi

    printf '%s' "$subject"
}

matches() {
    local value="$1"
    local pattern="$2"
    printf '%s\n' "$value" | grep -Eqi -- "$pattern"
}

while IFS=$'\t' read -r sha subject; do
    [[ -z "${sha:-}" ]] && continue

    lower="$(printf '%s' "$subject" | tr '[:upper:]' '[:lower:]')"
    clean_subject="$(normalize_subject "$subject")"
    entry="$(format_commit "$sha" "$clean_subject")"

    if matches "$lower" 'breaking[[:space:]_-]*change|^[^:]+!:[[:space:]]'; then
        breaking+=("$entry")
    elif matches "$lower" '^(security|sec)(\([^)]*\))?!?:[[:space:]]|bezpeč|secur'; then
        security+=("$entry")
    elif matches "$lower" '^(feat|feature)(\([^)]*\))?!?:[[:space:]]|^(add|added|implement)[[:space:]]|^(přid|nová|nový|nové|implement)'; then
        features+=("$entry")
    elif matches "$lower" '^(fix|bugfix|hotfix)(\([^)]*\))?!?:[[:space:]]|^(fix|fixed|repair)[[:space:]]|^(oprav|oprava)'; then
        fixes+=("$entry")
    elif matches "$lower" '^(perf|performance)(\([^)]*\))?!?:[[:space:]]|optimi|výkon'; then
        performance+=("$entry")
    elif matches "$lower" '^refactor(\([^)]*\))?!?:[[:space:]]|refactor'; then
        refactor+=("$entry")
    elif matches "$lower" '^(docs|doc)(\([^)]*\))?!?:[[:space:]]|readme|dokument'; then
        docs+=("$entry")
    elif matches "$lower" '^(ci|build|chore|deps|dependencies)(\([^)]*\))?!?:[[:space:]]|depend|workflow|github[[:space:]_-]*action'; then
        ci+=("$entry")
    else
        other+=("$entry")
    fi
done < <(git log --no-merges --format=$'%H\t%s' "$RANGE")

write_section() {
    local title="$1"
    local array_name="$2"
    local -n entries="$array_name"

    if (( ${#entries[@]} == 0 )); then
        return
    fi

    printf '## %s\n\n' "$title" >> "$OUTPUT"
    for item in "${entries[@]}"; do
        printf -- '- %s\n' "$item" >> "$OUTPUT"
    done
    printf '\n' >> "$OUTPUT"
}

: > "$OUTPUT"
printf '# EV Stats %s\n\n' "$TAG" >> "$OUTPUT"

if [[ -n "$PREVIOUS_TAG" ]]; then
    printf 'Přehled změn od verze **%s**.\n\n' "$PREVIOUS_TAG" >> "$OUTPUT"
else
    printf 'První publikované vydání projektu.\n\n' >> "$OUTPUT"
fi

write_section '💥 Zásadní změny' breaking
write_section '🚀 Nové funkce' features
write_section '🐛 Opravy' fixes
write_section '🔐 Bezpečnost' security
write_section '⚡ Výkon a optimalizace' performance
write_section '♻️ Refaktoring' refactor
write_section '📚 Dokumentace' docs
write_section '🧰 CI/CD a údržba' ci
write_section '🔧 Ostatní změny' other

commit_count="$(git rev-list --count "$RANGE")"
if [[ "$commit_count" == "0" ]]; then
    printf '> V tomto vydání nebyly oproti předchozímu tagu nalezeny žádné nové commity.\n\n' >> "$OUTPUT"
fi

if [[ -n "$REPOSITORY_URL" && -n "$PREVIOUS_TAG" ]]; then
    printf '**Kompletní porovnání:** [%s...%s](%s/compare/%s...%s)\n' \
        "$PREVIOUS_TAG" "$TAG" "$REPOSITORY_URL" "$PREVIOUS_TAG" "$TAG" >> "$OUTPUT"
fi
