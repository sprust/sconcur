package connection

import (
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"

	"go.mongodb.org/mongo-driver/v2/mongo/options"
)

// updateOptionsBuilder is satisfied by both *UpdateOneOptionsBuilder and
// *UpdateManyOptionsBuilder, which v2 split into separate types.
type updateOptionsBuilder[T any] interface {
	SetHint(any) T
	SetCollation(*options.Collation) T
	SetArrayFilters([]any) T
}

// deleteOptionsBuilder is satisfied by both *DeleteOneOptionsBuilder and
// *DeleteManyOptionsBuilder.
type deleteOptionsBuilder[T any] interface {
	SetHint(any) T
	SetCollation(*options.Collation) T
}

// applyUpdateOptions applies the shared hint/collation/arrayFilters options to an update.
func applyUpdateOptions[T updateOptionsBuilder[T]](opts T, params *objects.UpdateParams) error {
	if hint := serializer.ParseHint(params.Hint); hint != nil {
		opts.SetHint(hint)
	}

	collation, err := serializer.ParseCollation(params.Collation)

	if err != nil {
		return err
	}

	if collation != nil {
		opts.SetCollation(collation)
	}

	arrayFilters, err := serializer.ParseArrayFilters(params.ArrayFilters)

	if err != nil {
		return err
	}

	if len(arrayFilters) > 0 {
		opts.SetArrayFilters(arrayFilters)
	}

	return nil
}

func applyFindOptions(opts *options.FindOptionsBuilder, hint, collation []byte) error {
	if h := serializer.ParseHint(hint); h != nil {
		opts.SetHint(h)
	}

	coll, err := serializer.ParseCollation(collation)

	if err != nil {
		return err
	}

	if coll != nil {
		opts.SetCollation(coll)
	}

	return nil
}

func applyFindOneOptions(opts *options.FindOneOptionsBuilder, hint, collation []byte) error {
	if h := serializer.ParseHint(hint); h != nil {
		opts.SetHint(h)
	}

	coll, err := serializer.ParseCollation(collation)

	if err != nil {
		return err
	}

	if coll != nil {
		opts.SetCollation(coll)
	}

	return nil
}

func applyDeleteOptions[T deleteOptionsBuilder[T]](opts T, hint, collation []byte) error {
	if h := serializer.ParseHint(hint); h != nil {
		opts.SetHint(h)
	}

	coll, err := serializer.ParseCollation(collation)

	if err != nil {
		return err
	}

	if coll != nil {
		opts.SetCollation(coll)
	}

	return nil
}

func applyDistinctOptions(opts *options.DistinctOptionsBuilder, collation []byte) error {
	coll, err := serializer.ParseCollation(collation)

	if err != nil {
		return err
	}

	if coll != nil {
		opts.SetCollation(coll)
	}

	return nil
}

// applyFindOneAndUpdateOptions applies hint/collation/arrayFilters to a findOneAndUpdate.
func applyFindOneAndUpdateOptions(opts *options.FindOneAndUpdateOptionsBuilder, params *objects.FindOneAndUpdateParams) error {
	if hint := serializer.ParseHint(params.Hint); hint != nil {
		opts.SetHint(hint)
	}

	collation, err := serializer.ParseCollation(params.Collation)

	if err != nil {
		return err
	}

	if collation != nil {
		opts.SetCollation(collation)
	}

	arrayFilters, err := serializer.ParseArrayFilters(params.ArrayFilters)

	if err != nil {
		return err
	}

	if len(arrayFilters) > 0 {
		opts.SetArrayFilters(arrayFilters)
	}

	return nil
}
