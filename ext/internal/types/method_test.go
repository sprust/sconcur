package types

import (
	"testing"
	"unsafe"
)

// InternMethod exists so the cgo boundary can resolve a method from a string
// that only views the C buffer, without copying it per push. The guarantee is
// about backing memory, not about the value: a known method must come back
// pointing at the canonical constant, so nothing retains the caller's bytes.
func TestInternMethodReturnsTheCanonicalInstance(t *testing.T) {
	known := []Method{
		MethodSleep,
		MethodMongodb,
		MethodHttpServe,
		MethodHttpRespond,
		MethodHttpClient,
		MethodMysql,
		MethodPgsql,
		MethodSocketServe,
		MethodSocketRespond,
		MethodSocketClient,
		MethodWsServe,
		MethodWsRespond,
		MethodWsClient,
	}

	for _, canonical := range known {
		// A fresh copy of the bytes stands in for the cgo view: same value,
		// different backing array.
		view := Method(string(append([]byte(nil), canonical...)))

		if unsafe.StringData(string(view)) == unsafe.StringData(string(canonical)) {
			t.Fatalf("%q: the test copy shares the constant's backing array, nothing is proven", canonical)
		}

		interned := InternMethod(view)

		if interned != canonical {
			t.Fatalf("expected %q, got %q", canonical, interned)
		}

		if unsafe.StringData(string(interned)) != unsafe.StringData(string(canonical)) {
			t.Fatalf("%q was not interned: the result still points at the caller's bytes", canonical)
		}
	}
}

// An unknown method is the one case that must allocate: the result outlives the
// cgo call, so it may not keep viewing the C buffer.
func TestInternMethodClonesAnUnknownMethod(t *testing.T) {
	view := Method(string(append([]byte(nil), "zz"...)))

	interned := InternMethod(view)

	if interned != "zz" {
		t.Fatalf("expected the value to survive, got %q", interned)
	}

	if unsafe.StringData(string(interned)) == unsafe.StringData(string(view)) {
		t.Fatal("an unknown method must be cloned, not viewed")
	}
}

// The intern table must cover exactly the declared method set: a method added to
// the constants but forgotten here would allocate on every push instead of
// interning, silently undoing the optimization.
func TestInternedMethodsCoverEveryConstant(t *testing.T) {
	for method, canonical := range internedMethods {
		if method != canonical {
			t.Fatalf("intern table maps %q onto %q", method, canonical)
		}
	}

	if len(internedMethods) != 13 {
		t.Fatalf("expected 13 interned methods, got %d — update the table and this count together", len(internedMethods))
	}
}
