# Pikkumies backup receiver (ADR-0049), the forced command of one restricted key: sh recv.sh <label> <keep-days>.
# POSIX sh, because the backup machine may have no PHP. Accepts only put|get <name>, commit <name> <bytes>, list and df
# in SSH_ORIGINAL_COMMAND; archives live in ~/pikkumies-backups/<label>/ and are never overwritten.
set -eu
umask 077
label=$1 keep=$2
case $label in ''|.*|*[!A-Za-z0-9._-]*) echo 'refused: bad label' >&2; exit 2 ;; esac
case $keep in ''|*[!0-9]*) echo 'refused: bad keep-days' >&2; exit 2 ;; esac
set -f
set -- ${SSH_ORIGINAL_COMMAND:-}
verb=${1:-} name=${2:-} size=${3:-}
d='[0-9][0-9]'
m="$d$d$d${d}T$d$d${d}Z.meta.tgz.gpg" f="$d$d$d${d}T$d$d${d}Z.full.tgz.gpg"
re='^[0-9]{8}T[0-9]{6}Z\.(meta|full)\.tgz\.gpg$'
case $verb:$name:$size:$# in
  put:$m::2|put:$f::2|get:$m::2|get:$f::2|list:::1|df:::1) ;;
  commit:$m:*:3|commit:$f:*:3) case $size in *[!0-9]*) echo 'refused: bad size' >&2; exit 2 ;; esac ;;
  *) echo 'refused: only put|get <name>, commit <name> <bytes>, list, df' >&2; exit 2 ;;
esac
mkdir -p "$HOME/pikkumies-backups/$label"
cd "$HOME/pikkumies-backups/$label"
case $verb in
  put) find . -name '*.part' -mtime +0 -exec rm -f {} +
    [ ! -e "$name" ] || { echo "refused: $name exists" >&2; exit 2; }
    set -C; cat > "$name.part"; n=$(wc -c < "$name.part"); echo "received $n" ;;
  commit) n=$(wc -c < "$name.part"); [ $n -eq "$size" ] || { echo "refused: $n bytes, not $size" >&2; exit 2; }
    ln "$name.part" "$name"; rm -f "$name.part"
    for k in meta full; do
      ls | grep -E "^[0-9]{8}T[0-9]{6}Z\.$k\.tgz\.gpg$" | sort -r | tail -n +4 | while read -r old; do
        [ -z "$(find "$old" -mtime +"$keep")" ] || rm -f "$old"; done
    done
    echo "committed $name" ;;
  get) cat "$name" ;;
  list) for a in $(ls | grep -E "$re"); do n=$(wc -c < "$a"); echo "$a $n"; done ;;
  df) df -Pk . | awk 'NR == 2 { print "avail_kib", $4 }' ;;
esac
