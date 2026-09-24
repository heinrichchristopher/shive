#!/bin/bash
# Build the Slackware-style package + update MD5 in shive.plg.
#   ./build.sh [version]     (default: today, YYYY.MM.DD)
set -euo pipefail
VER="${1:-$(date +%Y.%m.%d)}"
PKG="shive-${VER}-noarch-1.txz"
rm -rf build && mkdir -p build/pkg && cp -a src/. build/pkg/
mkdir -p build/pkg/install
cat > build/pkg/install/slack-desc <<'D'
shive: shive (ZFS snapshot scheduling, retention & replication for Unraid)
shive:
shive: Generic ZFS snapshot schedules with GFS/age retention, optional Docker
shive: quiesce/resume, local + SSH replication, snapshot browser/restore.
D
chmod 0755 build/pkg/usr/local/emhttp/plugins/shive/scripts/shive-* build/pkg/usr/local/emhttp/plugins/shive/event/*
# Reproducible: identical sources must produce an identical package, because the MD5 that ends up
# in the committed shive.plg has to match the package the release attaches (built independently
# by the GitHub Actions runner). GNU tar's --sort/--mtime/--owner flags achieve this on Linux, but
# this script also has to run on whatever machine tags a release - e.g. a Mac, where /usr/bin/tar
# is bsdtar (libarchive) and doesn't understand those flags at all, and even if it did, two
# different tar implementations aren't guaranteed to encode "the same" archive identically byte
# for byte. Python's tarfile module sidesteps both problems: it never shells out to the platform's
# tar binary, so the exact same interpreter code produces the exact same bytes on Linux and macOS.
python3 - "$PKG" <<'PYEOF'
import tarfile, os, sys, lzma, io
pkg = sys.argv[1]
# tarfile.open(mode="w:xz") leaves the compression preset/check to lzma's own defaults, which are
# NOT guaranteed identical across Python/liblzma builds (macOS's bundled liblzma vs. Ubuntu's) even
# for byte-identical input - the exact failure mode this reproducibility fix was supposed to close.
# Pin every parameter explicitly so the compressed output depends only on the input bytes.
filters = [{"id": lzma.FILTER_LZMA2, "preset": 6}]
buf = io.BytesIO()
with tarfile.open(fileobj=buf, mode="w") as tf:
    for root, dirs, files in os.walk("build/pkg"):
        dirs.sort()
        for name in sorted(files):
            path = os.path.join(root, name)
            arcname = "." + path[len("build/pkg"):]
            info = tf.gettarinfo(path, arcname=arcname)
            info.mtime = 0; info.uid = 0; info.gid = 0; info.uname = ""; info.gname = ""
            with open(path, "rb") as f:
                tf.addfile(info, f)
with open(f"build/{pkg}", "wb") as out:
    out.write(lzma.compress(buf.getvalue(), format=lzma.FORMAT_XZ, check=lzma.CHECK_CRC32, filters=filters))
PYEOF
MD5=$(md5sum "build/$PKG" | cut -d' ' -f1)
sed -i -e "s/<!ENTITY version   \"[^\"]*\">/<!ENTITY version   \"$VER\">/" \
       -e "s/<!ENTITY pkgMD5    \"[^\"]*\">/<!ENTITY pkgMD5    \"$MD5\">/" shive.plg
xmllint --noout shive.plg 2>/dev/null || { echo "shive.plg is not well-formed XML"; exit 1; }
# Local variant: reads the package from the flash drive instead of a GitHub release.
# It MUST be named shive.plg: update_cron derives the directories it scans from the symlink
# names in /var/log/plugins, so a "shive-local.plg" would make it look in
# /boot/config/plugins/shive-local/ and never find our shive.cron.
mkdir -p build/local
sed "s#<URL>.*</URL>#<URL>file:///boot/config/plugins/shive/$PKG</URL>#" shive.plg > "build/local/shive.plg"
echo "built build/$PKG  md5=$MD5  (shive.plg updated, XML validated)"
echo "Local test install on the Unraid box:"
echo "  mkdir -p /boot/config/plugins/shive && cp build/$PKG /boot/config/plugins/shive/"
echo "  cp build/local/shive.plg /boot/config/plugins/"
echo "  plugin install /boot/config/plugins/shive.plg"
