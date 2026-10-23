// std：同样三个路由，改用 net/http。作为 minihttp 的对照组。
package main

import (
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"time"
)

func main() {
	addr := flag.String("addr", ":9003", "监听地址")
	flag.Parse()

	mux := http.NewServeMux()
	mux.HandleFunc("/hello", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		io.WriteString(w, "hello\n")
	})
	mux.HandleFunc("/echo", func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		s := fmt.Sprintf("method=%s path=%s query=%s cl=%d chunked=%v bytes=%d\n%s",
			r.Method, r.URL.Path, r.URL.RawQuery, r.ContentLength,
			len(r.TransferEncoding) > 0 && r.TransferEncoding[0] == "chunked", len(body), body)
		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		io.WriteString(w, s)
	})
	mux.HandleFunc("/sleep", func(w http.ResponseWriter, r *http.Request) {
		time.Sleep(2 * time.Second)
		io.WriteString(w, "slept\n")
	})

	srv := &http.Server{
		Addr:              *addr,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      10 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
	log.Printf("stdhttp listening on %s (net/http)", *addr)
	log.Fatal(srv.ListenAndServe())
}
