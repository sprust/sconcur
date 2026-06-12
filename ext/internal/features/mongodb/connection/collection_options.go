package connection

import (
	"sconcur/internal/features/mongodb/objects"
	"sconcur/internal/features/mongodb/serializer"

	"go.mongodb.org/mongo-driver/mongo/options"
)

// applyUpdateOptions applies the shared hint/collation/arrayFilters options to an update.
func applyUpdateOptions(opts *options.UpdateOptions, params *objects.UpdateParams) error {
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
		opts.SetArrayFilters(options.ArrayFilters{Filters: arrayFilters})
	}

	return nil
}

func applyFindOptions(opts *options.FindOptions, hint, collation []byte) error {
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

func applyFindOneOptions(opts *options.FindOneOptions, hint, collation []byte) error {
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

func applyDeleteOptions(opts *options.DeleteOptions, hint, collation []byte) error {
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

func applyDistinctOptions(opts *options.DistinctOptions, collation []byte) error {
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
func applyFindOneAndUpdateOptions(opts *options.FindOneAndUpdateOptions, params *objects.FindOneAndUpdateParams) error {
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
		opts.SetArrayFilters(options.ArrayFilters{Filters: arrayFilters})
	}

	return nil
}
