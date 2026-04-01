<?php

namespace SConcur\Features;

enum MethodEnum: int
{
    case Unknown           = 0;
    case Sleep             = 1;
    case MongodbCollection = 2;
    case Mysql             = 3;
}
