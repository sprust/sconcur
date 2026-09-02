package serializer

import (
	"fmt"
	"strings"
	"testing"

	"go.mongodb.org/mongo-driver/v2/bson"
)

// The raw BSON path cost the Go side nothing — the driver's bytes went straight
// to PHP. MessagePack moves that work here, off the single PHP thread, so the
// price is worth watching: this is the number that grew from zero.
func typicalDocument(payloadSize int) bson.Raw {
	objectId, _ := bson.ObjectIDFromHex("6919e3d1a3673d3f4d9137a3")

	fields := bson.D{
		{Key: "_id", Value: objectId},
		{Key: "title", Value: "A document title of a fairly ordinary length"},
		{Key: "views", Value: int32(128374)},
		{Key: "rating", Value: 4.75},
		{Key: "active", Value: true},
		{Key: "created", Value: bson.DateTime(1755200000000)},
		{Key: "tags", Value: bson.A{"php", "go", "mongodb"}},
		{Key: "author", Value: bson.D{{Key: "id", Value: int32(42)}, {Key: "name", Value: "John Smith"}}},
	}

	if payloadSize > 0 {
		fields = append(fields, bson.E{Key: "payload", Value: strings.Repeat("a", payloadSize)})
	}

	raw, err := bson.Marshal(fields)

	if err != nil {
		panic(err)
	}

	return raw
}

func BenchmarkConversion(b *testing.B) {
	for _, size := range []int{0, 1024, 16384} {
		document := typicalDocument(size)

		encoded, err := BSONToMsgpack(document)

		if err != nil {
			b.Fatalf("encoding: %v", err)
		}

		b.Run(fmt.Sprintf("BSON->msgpack/%dB", len(document)), func(b *testing.B) {
			for i := 0; i < b.N; i++ {
				if _, err := BSONToMsgpack(document); err != nil {
					b.Fatal(err)
				}
			}
		})

		b.Run(fmt.Sprintf("msgpack->BSON/%dB", len(encoded)), func(b *testing.B) {
			for i := 0; i < b.N; i++ {
				if _, err := MsgpackToBSON(encoded); err != nil {
					b.Fatal(err)
				}
			}
		})
	}
}
