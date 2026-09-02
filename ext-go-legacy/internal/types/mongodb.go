package types

type MongodbCommand string

const (
	MongodbInsertOne              MongodbCommand = "ino"
	MongodbBulkWrite              MongodbCommand = "bw"
	MongodbAggregate              MongodbCommand = "agg"
	MongodbInsertMany             MongodbCommand = "inm"
	MongodbCountDocuments         MongodbCommand = "cnt"
	MongodbUpdateOne              MongodbCommand = "upo"
	MongodbFindOne                MongodbCommand = "fno"
	MongodbCreateIndex            MongodbCommand = "cix"
	MongodbDeleteOne              MongodbCommand = "dlo"
	MongodbDeleteMany             MongodbCommand = "dlm"
	MongodbUpdateMany             MongodbCommand = "upm"
	MongodbDrop                   MongodbCommand = "drp"
	MongodbDropIndex              MongodbCommand = "dix"
	MongodbFind                   MongodbCommand = "fnd"
	MongodbDistinct               MongodbCommand = "dst"
	MongodbFindOneAndUpdate       MongodbCommand = "fou"
	MongodbFindOneAndDelete       MongodbCommand = "fod"
	MongodbFindOneAndReplace      MongodbCommand = "for"
	MongodbReplaceOne             MongodbCommand = "rpo"
	MongodbEstimatedDocumentCount MongodbCommand = "edc"
	MongodbCreateIndexes          MongodbCommand = "cxs"
	MongodbListIndexes            MongodbCommand = "lix"
	MongodbListCollections        MongodbCommand = "lcl"
	MongodbListDatabases          MongodbCommand = "ldb"
	MongodbRenameCollection       MongodbCommand = "rnc"
	MongodbRunCommand             MongodbCommand = "run"
)
