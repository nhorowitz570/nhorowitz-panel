#!/usr/bin/env bash
# ServerOS daemon installer, as this panel serves it at /install.sh, with
# its own addresses filled in (App\Http\Controllers\DaemonInstallScriptController).
#
#   curl -fsSL __SERVEROS_INSTALL_URL__ | sudo bash -s -- --token=ENROL_TOKEN
#
# This script is short on purpose. Read it. It:
#   1. checks this is Linux with systemd, root, and a supported CPU
#   2. downloads the serverosd binary for this machine and verifies its
#      checksum and, when this panel has a release key, its signature
#   3. installs it to /usr/local/bin/serverosd
#   4. runs `serverosd enrol`, which does the rest and prints each step
#
# It does not touch any service, package, firewall rule, or file outside
# /usr/local/bin, /etc/serveros, /var/lib/serveros, /var/log/serveros and
# the serverosd systemd unit. Remove everything with `serverosd uninstall`.
#
# What the daemon can and can't do, and what leaves your server:
# https://github.com/serveroshq/daemon

set -euo pipefail

INSTALLER_VERSION="1"
PANEL="${SERVEROS_PANEL:-__SERVEROS_PANEL_URL__}"
RELEASES="${SERVEROS_RELEASES:-__SERVEROS_RELEASES_URL__}"
RELEASE_KEY="__SERVEROS_RELEASE_PUBKEY__"
VERSION="${SERVEROS_VERSION:-latest}"
TOKEN=""
BIN=/usr/local/bin/serverosd

for arg in "$@"; do
  case "$arg" in
    --token=*) TOKEN="${arg#--token=}" ;;
    --panel=*) PANEL="${arg#--panel=}" ;;
    --releases=*) RELEASES="${arg#--releases=}" ;;
    --version=*) VERSION="${arg#--version=}" ;;
    --help|-h)
      sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

say()  { printf '  %s\n' "$*"; }
fail() { printf '\nerror: %s\n' "$*" >&2; exit 1; }

echo "ServerOS daemon installer (v$INSTALLER_VERSION)"
echo

[ "$(id -u)" -eq 0 ] || fail "run this as root (sudo). The daemon manages services, so it installs as root."
[ "$(uname -s)" = "Linux" ] || fail "the daemon runs on Linux only (this is $(uname -s))."
[ -d /run/systemd/system ] || fail "systemd was not found. The daemon needs systemd to stay running; other init systems are not supported yet."
[ -n "$TOKEN" ] || fail "no enrolment token. Copy the full command from the panel: it ends with --token=…"
[ -n "$PANEL" ] || fail "no panel address. Copy the full command from the panel, or add --panel=https://your-panel."

case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) fail "unsupported CPU architecture $(uname -m); amd64 and arm64 are supported." ;;
esac

if [ -x "$BIN" ] && [ -f /etc/serveros/daemon.toml ]; then
  fail "ServerOS is already installed on this machine ($("$BIN" version 2>/dev/null || echo unknown)). Run 'serverosd disconnect --yes' first to enrol it again."
fi

command -v curl >/dev/null || fail "curl is required; install it and re-run."

if [ "$VERSION" = "latest" ]; then
  VERSION="$(curl -fsSL "$RELEASES/stable/VERSION" 2>/dev/null || true)"
  [ -n "$VERSION" ] || fail "could not read the latest version from $RELEASES (check outbound HTTPS on port 443)."
fi

ASSET="serverosd-${VERSION}-linux-${ARCH}"
URL="$RELEASES/${VERSION}/${ASSET}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

say "machine:  $(hostname) ($(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME" || echo Linux), $ARCH)"
say "version:  $VERSION"
say "download: $URL"
echo

curl -fsSL --retry 3 -o "$TMP/$ASSET" "$URL" \
  || fail "could not download $URL. Check outbound HTTPS (port 443) and DNS on this machine."
curl -fsSL --retry 3 -o "$TMP/SHA256SUMS" "$RELEASES/${VERSION}/SHA256SUMS" \
  || fail "could not download the checksum file for $VERSION."

EXPECTED="$(grep " ${ASSET}\$" "$TMP/SHA256SUMS" | awk '{print $1}')"
[ -n "$EXPECTED" ] || fail "no checksum published for $ASSET; refusing to install an unverifiable binary."
ACTUAL="$(sha256sum "$TMP/$ASSET" | awk '{print $1}')"
[ "$EXPECTED" = "$ACTUAL" ] || fail "checksum mismatch for $ASSET (expected $EXPECTED, got $ACTUAL). The download is corrupt or tampered with; nothing was installed."
say "checksum verified"

if [ -n "$RELEASE_KEY" ]; then
  SIGNATURE="$(curl -fsSL "$PANEL/api/daemon/releases/latest?arch=${ARCH}&version=${VERSION//+/%2B}" 2>/dev/null \
    | sed -n 's/.*"signature":"\([^"]*\)".*/\1/p' | tr -d '\\' || true)"
  [ -n "$SIGNATURE" ] || fail "no signature published for $ASSET on $PANEL; refusing to install an unverifiable binary."
  printf '%s' "$SIGNATURE" | base64 -d > "$TMP/release.sig" 2>/dev/null || fail "the signature for $ASSET isn't valid base64."
  SIGNED=""
  if command -v openssl >/dev/null && case "$(openssl pkeyutl -help 2>&1 || true)" in *-rawin*) true ;; *) false ;; esac; then
    printf "$(printf '302a300506032b6570032100%s' "$RELEASE_KEY" | sed 's/../\\x&/g')" > "$TMP/release.pub"
    openssl pkeyutl -verify -pubin -keyform DER -inkey "$TMP/release.pub" -rawin -in "$TMP/$ASSET" -sigfile "$TMP/release.sig" >/dev/null 2>&1 && SIGNED=1
  elif command -v python3 >/dev/null; then
    cat > "$TMP/verify.py" <<'PY'
import sys, hashlib
p = 2**255 - 19; L = 2**252 + 27742317777372353535851937790883648493
d = -121665 * pow(121666, p - 2, p) % p
def add(P, Q):
    A = (P[1] - P[0]) * (Q[1] - Q[0]) % p; B = (P[1] + P[0]) * (Q[1] + Q[0]) % p
    C = 2 * d * P[3] * Q[3] % p; D = 2 * P[2] * Q[2] % p
    E, F, G, H = B - A, D - C, D + C, B + A
    return (E * F % p, G * H % p, F * G % p, E * H % p)
def mul(s, P):
    Q = (0, 1, 1, 0)
    while s:
        if s & 1: Q = add(Q, P)
        P = add(P, P); s >>= 1
    return Q
def enc(P):
    zi = pow(P[2], p - 2, p); x = P[0] * zi % p; y = P[1] * zi % p
    return (y | (x & 1) << 255).to_bytes(32, "little")
def dec(b):
    y = int.from_bytes(b, "little"); sign = y >> 255; y &= (1 << 255) - 1
    if y >= p: raise ValueError
    u = (y * y - 1) * pow(d * y * y + 1, p - 2, p) % p
    x = pow(u, (p + 3) // 8, p)
    if (x * x - u) % p: x = x * pow(2, (p - 1) // 4, p) % p
    if (x * x - u) % p or (x == 0 and sign): raise ValueError
    if x & 1 != sign: x = p - x
    return (x, y, 1, x * y % p)
pub = bytes.fromhex(sys.argv[1]); sig = open(sys.argv[2], "rb").read(); msg = open(sys.argv[3], "rb").read()
if len(pub) != 32 or len(sig) != 64: sys.exit(1)
S = int.from_bytes(sig[32:], "little")
if S >= L: sys.exit(1)
try: A = dec(pub); R = dec(sig[:32])
except ValueError: sys.exit(1)
h = int.from_bytes(hashlib.sha512(sig[:32] + pub + msg).digest(), "little") % L
B = dec((4 * pow(5, p - 2, p) % p).to_bytes(32, "little"))
sys.exit(0 if enc(mul(S, B)) == enc(add(R, mul(h, A))) else 1)
PY
    python3 -I "$TMP/verify.py" "$RELEASE_KEY" "$TMP/release.sig" "$TMP/$ASSET" && SIGNED=1
  else
    fail "checking the release signature needs OpenSSL 3 or python3, and this machine has neither. Install python3 and re-run."
  fi
  [ -n "$SIGNED" ] || fail "signature check failed for $ASSET. The download is tampered with, or not a ServerOS release; nothing was installed."
  say "signature verified"
else
  say "signature not checked (this panel has no release key set)"
fi

install -m 0755 "$TMP/$ASSET" "$BIN"
say "installed $BIN"
echo

exec "$BIN" enrol --token="$TOKEN" --panel="$PANEL"
