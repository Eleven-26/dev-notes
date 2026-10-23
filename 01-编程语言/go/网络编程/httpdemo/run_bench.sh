#!/bin/sh
cd /src || exit 1
for i in 1 2 3 4 5; do
  echo "== round $i"
  echo -n "mini: "; ./bin/bench -addr 127.0.0.1:9004 -c 8 -n 30000 | grep qps
  echo -n "std : "; ./bin/bench -addr 127.0.0.1:9003 -c 8 -n 30000 | grep qps
done
