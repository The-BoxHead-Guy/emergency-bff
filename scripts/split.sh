#!/usr/bin/env bash
set -euo pipefail

# Manual mirror of packages/emergency-bff to the public split repository.
# Fallback for when GitHub Actions minutes are exhausted.
#
# The mirror is built from the committed tree (git archive), not from the
# working directory, so untracked files (vendor/, composer.lock, caches) can
# never leak into the public repository.

readonly PACKAGE_NAME="dataemergencia/emergency-bff"
readonly DEFAULT_REMOTE="git@github.com:The-BoxHead-Guy/emergency-bff.git"

usage() {
  cat <<'EOF'
Usage: bash scripts/split.sh <version> [options]

  <version>        Release version in X.Y.Z form, for example 0.1.0
  --push           Commit and push the mirror plus the split tag (default: dry run)
  --yes, -y        Skip the interactive confirmation (required when not a TTY)
  --skip-tests     Skip PHPUnit (Composer validation and php -l still run)
  --keep           Keep the temporary split checkout for inspection
  --remote <url>   Split repository remote (default: SPLIT_REMOTE or the SSH URL)
  -h, --help       Show this help

The script:
  1. Refuses to run when the package has uncommitted or untracked files.
  2. Runs composer validate --strict, php -l and PHPUnit.
  3. Mirrors the committed package tree into a clone of the split repository.
  4. Shows the exact changes, then pushes main and v<version> only with --push.
  5. Creates the local monorepo tag php-v<version> (never pushes it).

Environment:
  SPLIT_REMOTE     Overrides the split repository remote.
EOF
}

die() {
  printf 'error: %s\n' "$*" >&2
  exit 1
}

step() {
  printf '\n==> %s\n' "$*"
}

VERSION=""
PUSH=false
ASSUME_YES=false
SKIP_TESTS=false
KEEP=false
REMOTE="${SPLIT_REMOTE:-$DEFAULT_REMOTE}"

while (($#)); do
  case "$1" in
    --push) PUSH=true ;;
    --yes | -y) ASSUME_YES=true ;;
    --skip-tests) SKIP_TESTS=true ;;
    --keep) KEEP=true ;;
    --remote)
      shift
      (($#)) || die "--remote requires a URL"
      REMOTE="$1"
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    -*) die "unknown option: $1" ;;
    *)
      [[ -z "$VERSION" ]] || die "unexpected argument: $1"
      VERSION="$1"
      ;;
  esac
  shift
done

if [[ -z "$VERSION" ]]; then
  usage
  exit 1
fi

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] ||
  die "version must be X.Y.Z, got: $VERSION"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(git -C "$SCRIPT_DIR" rev-parse --show-toplevel)"
PKG="$ROOT/packages/emergency-bff"
TAG="php-v$VERSION"
SPLIT_TAG="v$VERSION"

[[ -f "$PKG/composer.json" ]] || die "packages/emergency-bff/composer.json not found"

cd "$ROOT"

step "Preflight"
dirty="$(git status --porcelain --untracked-files=all -- "$PKG")"
if [[ -n "$dirty" ]]; then
  printf '%s\n' "$dirty" >&2
  die "the package has uncommitted or untracked files; commit them before releasing"
fi

grep -q "\"name\": \"$PACKAGE_NAME\"" "$PKG/composer.json" ||
  die "composer.json name must be $PACKAGE_NAME"

if grep -Eq '"version"[[:space:]]*:' "$PKG/composer.json"; then
  die "composer.json must not declare a version; Packagist derives it from tags"
fi

grep -q "## \[$VERSION\]" "$PKG/CHANGELOG.md" ||
  die "CHANGELOG.md has no '## [$VERSION]' entry"

(cd "$PKG" && composer validate --strict --no-interaction >/dev/null)
echo "composer.json is valid"

(cd "$PKG" &&
  find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null)
echo "PHP syntax is valid"

if [[ "$SKIP_TESTS" == false ]]; then
  [[ -x "$PKG/vendor/bin/phpunit" ]] ||
    die "vendor/bin/phpunit is missing; run 'composer install' in $PKG"
  (cd "$PKG" && vendor/bin/phpunit >/dev/null)
  echo "PHPUnit passed"
fi

SOURCE_REF="HEAD"
if git rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  TAG_COMMIT="$(git rev-parse "$TAG^{commit}")"
  HEAD_COMMIT="$(git rev-parse HEAD)"
  [[ "$TAG_COMMIT" == "$HEAD_COMMIT" ]] ||
    die "tag $TAG points to ${TAG_COMMIT:0:12}, not HEAD (${HEAD_COMMIT:0:12}); check out that commit or choose another version"
  SOURCE_REF="$TAG"
  echo "Mirroring existing tag $TAG"
else
  echo "Mirroring HEAD; tag $TAG will be created after a successful push"
fi

step "Cloning split repository"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/emergency-bff-split.XXXXXX")"
WORKDIR="$WORK/split"
if ! git clone --quiet "$REMOTE" "$WORKDIR"; then
  die "could not clone $REMOTE; check your SSH key or pass --remote"
fi

# The split repository is a generated mirror. Remove everything except .git.
find "$WORKDIR" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf -- {} +

git archive --format=tar "$SOURCE_REF:packages/emergency-bff" |
  tar -xf - -C "$WORKDIR"

for forbidden in vendor composer.lock .phpunit.cache .env .env.local; do
  [[ ! -e "$WORKDIR/$forbidden" ]] ||
    die "refusing to publish: $forbidden ended up in the mirror"
done
[[ -f "$WORKDIR/composer.json" ]] ||
  die "mirror has no composer.json; check the archive source"

step "Mirror contents"
cd "$WORKDIR"
git add -A
CHANGES="$(git status --porcelain)"
if [[ -n "$CHANGES" ]]; then
  printf '%s\n' "$CHANGES"
  git diff --cached --stat
else
  echo "No content changes (the split repository already matches $SOURCE_REF)"
fi

if [[ "$PUSH" == false ]]; then
  step "Dry run"
  echo "Nothing was pushed. Inspect the mirror at: $WORKDIR"
  echo "Re-run with --push to publish $SPLIT_TAG."
  exit 0
fi

if [[ "$ASSUME_YES" == false ]]; then
  [[ -t 0 ]] || die "non-interactive session; pass --yes to push without a prompt"
  printf '\nPush %s to main and force-update tag %s in %s? [y/N] ' \
    "$PACKAGE_NAME" "$SPLIT_TAG" "$REMOTE"
  read -r reply
  [[ "$reply" == "y" || "$reply" == "Y" || "$reply" == "yes" ]] ||
    die "aborted; the temporary mirror is at $WORKDIR"
fi

step "Pushing mirror"
if [[ -n "$CHANGES" ]]; then
  git commit --quiet -m "release: $SPLIT_TAG"
fi
git push origin HEAD:main
git tag -f "$SPLIT_TAG"
git push --force origin "$SPLIT_TAG"

git ls-remote --tags origin "refs/tags/$SPLIT_TAG" | grep -q . ||
  die "pushed, but $SPLIT_TAG is not visible on the remote"

step "Monorepo tag"
if git -C "$ROOT" rev-parse -q --verify "refs/tags/$TAG" >/dev/null; then
  echo "Monorepo tag $TAG already exists"
else
  git -C "$ROOT" tag -a "$TAG" -m "release: $VERSION"
  echo "Created local monorepo tag $TAG"
  echo "Push it when Actions quota allows: git -C \"$ROOT\" push origin $TAG"
fi

if [[ "$KEEP" == false ]]; then
  rm -rf -- "$WORK"
fi

cat <<EOF

Published $SPLIT_TAG from $(git -C "$ROOT" rev-parse --short "$SOURCE_REF").

Next steps:
  1. Packagist: https://packagist.org/packages/submit
     Repository URL: https://github.com/The-BoxHead-Guy/emergency-bff
  2. Verify the version appears on the package page.
  3. Clean install:
       mkdir -p /tmp/emergency-bff-consumer && cd /tmp/emergency-bff-consumer
       composer init --no-interaction --name=example/consumer
       composer require $PACKAGE_NAME:^${VERSION%.*}
EOF
